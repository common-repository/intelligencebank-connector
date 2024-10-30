<?php

namespace IBConnector;

class App
{
    /**
     * @var array Config
     */
    private $config;

    /**
     * @var Logger Logger
     */
    private $logger;

    /**
     * App constructor
     */
    private function __construct()
    {
        // Require config
        $this->config = require_once dirname(BASE_FILE) . '/config/main.php';

        // Get Logger
        $this->logger = new Logger();
    }

    /**
     * Run the App
     */
    public static function run()
    {
        // Run on init
        add_action('init', [new self(), 'init']);
    }

    /**
     * Run on init
     */
    public function init()
    {
        // Register and Enqueue assets
        $this->enqueueAssets();

        // Register Gutenberg Block
        register_block_type(
            'ibc/embed',
            [
                'editor_script' => 'ibc-gutenberg',
                'render_callback' => [$this, 'renderBlock'],
            ]
        );

        // Add AJAX Upload handler
        $this->addHook('wp_ajax_ibc_upload', [$this, 'handleAjaxUpload']);
    }

    /**
     * Admin register assets
     */
    public function registerAssets()
    {
        // Define assets
        $assets = [
            [
                'type' => 'css',
                'handle' => 'ibc-css',
                'path' => '/assets/css/admin/app.css',
            ],
            [
                'type' => 'js',
                'handle' => 'ibc-classic',
                'path' => '/assets/js/admin/classic.js',
                'deps' => ['jquery'],
            ],
            [
                'type' => 'js',
                'handle' => 'ibc-gutenberg',
                'path' => '/assets/js/admin/gutenberg.js',
                // 'path' => '/assets/js/admin/build/index.js',
                'deps' => ['wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-polyfill', 'jquery'],
            ],
        ];

        // Register assets
        foreach ($assets as $asset) {
            $isJs = 'js' === $asset['type'];
            $func = $isJs ? 'wp_register_script' : 'wp_register_style';
            $func($asset['handle'], plugins_url($asset['path'], BASE_FILE), $asset['deps'] ?? [], filemtime(plugin_dir_path(BASE_FILE) . $asset['path']));

            // Pass vars
            if ($isJs) {
                wp_localize_script(
                    $asset['handle'],
                    'ibc',
                    [
                        'nonce' => wp_create_nonce('ibc'),
                        'url' => $this->config['url'],
                        'log' => $this->config['log'],
                    ]
                );
            }
        }
    }

    /**
     * Admin enqueue assets
     */
    public function enqueueAssets()
    {
        // Enqueue common & classic editor assets
        $this->addHook(
            'admin_enqueue_scripts',
            function () {
                $this->registerAssets();
                wp_enqueue_style('ibc-css');
                wp_enqueue_script('ibc-classic');
            }
        );

        // Enqueue common & classic editor assets for Elementor
        $this->addHook(
            'elementor/editor/after_enqueue_scripts',
            function () {
                $this->registerAssets();
                wp_enqueue_style('ibc-css');
                wp_enqueue_script('ibc-classic');
            }
        );
    }

    /**
     * Render Block
     *
     * @param array $atts
     * @return string
     */
    public function renderBlock(array $atts): string
    {
        if (empty($atts['type'])) {
            return 'Unable to render asset';
        }

        $errorMessage = 'IntelligenceBank: Unable to render media';

        switch ($atts['type']) {
            case 'image':
                if (!empty($atts['id']) && ($attachment = get_post($atts['id']))) {
                    $asset = wp_get_attachment_image($atts['id'], 'full');
                } else {
                    $asset = !empty($atts['url']) ? sprintf('<img src="%s" alt="%s"/>', $atts['url'], $atts['alt']) : $errorMessage;
                }
                break;

            case 'video':
                $asset = !empty($atts['url']) ? sprintf('<video src="%s" controls></video>', $atts['url']) : $errorMessage;
                break;

            case 'audio':
                $asset = !empty($atts['url']) ? sprintf('<audio src="%s" controls></audio>', $atts['url']) : $errorMessage;
                break;

            default:
                $asset = !empty($atts['url']) ? sprintf('<a href="%s">%s</a>', $atts['url'], $atts['name']) : $errorMessage;
                $atts['alt'] = '';
                break;
        }

        $caption = !empty($atts['showAlt']) ? sprintf('<figcaption>%s</figcaption>', $atts['alt'] ?? '') : '';

        return sprintf('<figure class="wp-block-ibc-embed wp-block-%s">%s%s</figure>', $atts['type'], $asset, $caption);
    }

    /**
     * AJAX Upload handler
     */
    public function handleAjaxUpload()
    {
        // Bail if wrong nonce
        if (!check_ajax_referer('ibc')) {
            throw new \Exception('Wrong nonce');
        }

        $this->log('Uploading asset...');

        // Bail if no data
        if (!isset($_POST['data'])) {
            throw new \Exception('Missing data in POST');
        }

        // Prepare data
        $data = $this->validateRequest($_POST['data']);
        $data = array_map('trim', $data);
        $aid = $data['_aid'] ?? '';

        // Try to download the file
        $tmp = $this->pr($this->downloadAsset($data['url'], $aid), 'Download error');

        // Mimic uploaded file
        $file = [
            'name' => $data['filename'],
            'type' => $data['type'],
            'size' => $data['filesize'],
            'tmp_name' => $tmp,
        ];

        // Require WP core files
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download file
        $attachmentId = $this->pr(media_handle_sideload($file, null), 'Upload error');

        // Attachment description
        $description = $data['description'] ?: $data['name'];

        // Attachment data
        $postArr = [
            'ID' => $attachmentId,
            'post_content' => $description,
            'post_excerpt' => $description,
        ];

        // Update attachment
        $this->pr(wp_update_post($postArr), 'Error while updating attachment data for ' . $attachmentId);

        // Image Alt text
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $description);

        // Prepare data for returning
        $attachmentData = $this->pr(wp_prepare_attachment_for_js($attachmentId), 'Error while preparing data for JS');

        // Return success
        $this->success('Done', $attachmentData);
    }

    /**
     * Validate upload request
     *
     * @param array $data
     * @return array
     * @throws \Exception
     */
    private function validateRequest(array $data)
    {
        $result = [];

        $fields = [
            [
                'name' => 'url',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'name',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'type',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'filename',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'filesize',
                'type' => 'number',
                'required' => true,
            ],
            [
                'name' => '_aid',
                'type' => 'text',
                'default' => '',
            ],
            [
                'name' => 'description',
                'type' => 'text',
                'default' => '',
            ],
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field['name'], $data)) {
                $result[$field['name']] = $this->sanitizeField($field['type'], $data[$field['name']]);
                continue;
            }

            if (!empty($field['required'])) {
                throw new \Exception(sprintf('Field "%s" is required', $field['name']));
            }

            $result[$field['name']] = array_key_exists('default', $field) ? $field['default'] : null;
        }

        return $result;
    }

    /**
     * Sanitize field value
     *
     * @param string $type
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeField(string $type, $value)
    {
        switch ($type) {
            case 'number':
                return (int)$value;
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Download file
     *
     * @param string $url
     * @param string $aid Needed for API v2
     * @return mixed
     */
    private function downloadAsset(string $url, string $aid = '')
    {
        $this->log('Downloading...');

        // Create temp file
        $file = wp_tempnam(basename(parse_url($url, PHP_URL_PATH)));

        // Bail if failed
        if (!$file) {
            $this->log('Could not create Temporary file');
            return false;
        }

        // Try to download asset
        $args = [
            'timeout' => 300,
            'stream' => true,
            'filename' => $file,
        ];

        // Aid cookie is needed in API v2 and not needed in API v3, left for BWC
        if ($aid) {
            $args['cookies']['_aid'] = $aid;
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            unlink($file);
            throw new \Exception(implode('. ', $response->get_error_messages()));
        }

        if (200 != wp_remote_retrieve_response_code($response)) {
            unlink($file);
            $message = 'Download error: ' . wp_remote_retrieve_response_message($response);
            throw new \Exception($message);
        }

        $this->log('Downloaded');

        return $file;
    }

    /**
     * WP_Error handler
     *
     * @param mixed $result
     * @param string $message
     * @return mixed
     */
    private function pr($result, string $message = '')
    {
        if (is_wp_error($result)) {
            $messages = implode('. ', $result->get_error_messages());
            $msg = $message ? $message . ': ' . $messages : $messages;
            throw new \Exception($msg);
        }

        return $result;
    }

    /**
     * Echo JSON success
     *
     * @param string $message
     * @param array $data
     */
    private function success(string $message = '', array $data = [])
    {
        $log = $message ? $message : 'Done';
        $this->log($log);

        $return = [
            'success' => true,
            'message' => $log,
            'data' => $data,
        ];

        wp_send_json($return);
    }

    /**
     * Echo JSON error
     *
     * @param string $message
     */
    private function error(string $message = '')
    {
        $log = $message ? 'Error: ' . $message : 'Error!';
        $this->log($log);

        $return = [
            'success' => false,
            'message' => $message,
        ];

        wp_send_json($return);
    }

    /**
     * Add log entry
     *
     * @param mixed $message
     */
    private function log($message)
    {
        if ($this->config['log']) {
            $this->logger->log($message);
        }
    }

    /**
     * WP Hook wrapper to use try/catch
     *
     * @param string $tag
     * @param callable $callback
     * @param int $priority
     */
    private function addHook(string $tag, callable $callback, int $priority = 10)
    {
        add_filter(
            $tag,
            function () use ($callback) {
                try {
                    return $callback(...func_get_args());
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                }

                return false;
            },
            $priority,
            100
        );
    }
}