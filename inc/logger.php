<?php

namespace IBConnector;

class Logger
{
    /**
     * @var array Log entries
     */
    private $log = [];

    /**
     * @var string Path to log file
     */
    private $path;

    /**
     * @var string Launched time
     */
    private $launched;

    /**
     * Logger constructor
     */
    public function __construct()
    {
        $this->launched = date('d.m.y H:i:s');
        $this->path = $this->getUploadsDir('ibc/logs') . '/log.txt';
    }

    /**
     * Add log entry
     *
     * @param mixed $message
     */
    public function log($message)
    {
        // Handle WP_Error
        if (is_wp_error($message)) {
            $message = implode(' | ', $message->get_error_messages());
        }

        // Add entry to collector
        $this->log[] = '[' . date('d.m.y H:i:s') . '] ' . print_r($message, true);
    }

    /**
     * Get WP Uploads dir path
     *
     * @param string $path Path within WP Uploads
     * @return string
     */
    public function getUploadsDir(string $path)
    {
        // Define vars
        $uploadDir = wp_upload_dir();
        $fullPath = $uploadDir['basedir'] . '/' . $path;

        // Create if not exists
        if (!file_exists($fullPath)) {
            $message = wp_mkdir_p($fullPath) ? "Dir $fullPath created successfully" : 'Error while creating dir ' . $fullPath;
            $this->log($message);
        }

        return $fullPath;
    }

    /**
     * FLush log entries on destruct
     */
    public function __destruct()
    {
        // Bail if no entries
        if (!$this->log) {
            return;
        }

        // Write logs
        $logFile = fopen($this->path, 'a');
        $log = "\nProcess launched: " . $this->launched . "\n" . implode("\n", $this->log) . "\n";
        fwrite($logFile, $log);
        fclose($logFile);
    }
}
