<?php
namespace Katzgrau\KLogger;

use DateTime;
use RuntimeException;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Finally, a light, permissions-checking logging class.
 *
 * Originally written for use with wpSearch
 *
 * Usage:
 * $log = new Katzgrau\KLogger\Logger('/var/log/', Psr\Log\LogLevel::INFO);
 * $log->info('Returned a million search results'); //Prints to the log file
 * $log->error('Oh dear.'); //Prints to the log file
 * $log->debug('x = 5'); //Prints nothing due to current severity threshhold
 *
 * @author  Kenny Katzgrau <katzgrau@gmail.com>
 * @since   July 26, 2008
 * @link    https://github.com/katzgrau/KLogger
 * @version 1.0.0
 */

/**
 * Class documentation
 */
class Logger extends AbstractLogger
{
    /**
     * KLogger options
     *  Anything options not considered 'core' to the logging library should be
     *  settable view the third parameter in the constructor
     *
     *  Core options include the log file path and the log threshold
     *
     * @var array
     */
    protected $options = array (
        'extension'      => 'txt',
        'dateFormat'     => 'Y-m-d G:i:s.u',
        'filename'       => false,
        'flushFrequency' => false,
        'prefix'         => 'log_',
        'logFormat'      => false,
        'appendContext'  => true,
        'fileGroups' => [
            'errorLogs' => [
                'logLevels' => [
                    LogLevel::EMERGENCY,
                    LogLevel::ALERT,
                    LogLevel::CRITICAL,
                    LogLevel::ERROR
                ],
                'suffix' => '-[ERROR]'
            ],
            'warningLogs' => [
                'logLevels' => [
                    LogLevel::WARNING
                ],
                'suffix' => '-[WARNING]'
            ],
            'debugLogs' => [
                'logLevels' => [
                    LogLevel::NOTICE,
                    LogLevel::INFO,
                    LogLevel::DEBUG
                ],
                'suffix' => '-[DEBUG]'
            ]
        ]
    );

    /**
     * Path to the log file
     * @var array
     */
    private $logFilePath = [];

    /**
     * Current minimum logging threshold
     * @var integer
     */
    protected $logLevelThreshold = LogLevel::DEBUG;

    /**
     * The number of lines logged in this instance's lifetime
     * @var int
     */
    private $logLineCount = 0;

    /**
     * Log Levels
     * @var array
     */
    protected $logLevels = array(
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7
    );

    /**
     * This holds the file handle for this instance's log file
     * @var array
     */
    private $fileHandle = [];

    /**
     * This holds the last line logged to the logger
     *  Used for unit tests
     * @var string
     */
    private $lastLine = '';

    /**
     * Octal notation for default permissions of the log file
     * @var integer
     */
    private $defaultPermissions = 0777;

    /**
     * Class constructor
     *
     * @param string $logDirectory      File path to the logging directory
     * @param string $logLevelThreshold The LogLevel Threshold
     * @param array  $options
     *
     * @internal param string $logFilePrefix The prefix for the log file name
     * @internal param string $logFileExt The extension for the log file
     */
    public function __construct($logDirectory, $logLevelThreshold = LogLevel::DEBUG, array $options = array())
    {
        $this->logLevelThreshold = $logLevelThreshold;
        $this->options = array_merge($this->options, $options);

        $logDirectory = rtrim($logDirectory, DIRECTORY_SEPARATOR);
        if (! file_exists($logDirectory)) {
            mkdir($logDirectory, $this->defaultPermissions, true);
        }

        if (strpos($logDirectory, 'php://') === 0) {
            $this->setLogToStdOut($logDirectory);
            $this->setFileHandle('w+');
        } elseif (!empty($this->options['fileGroups']) && is_array($this->options['fileGroups'])) {
            foreach ($this->options['fileGroups'] as $optIndex => $setting) {
                $this->setLogFilePath($logDirectory, $optIndex, $setting['suffix']);
                if (file_exists($this->logFilePath[$optIndex]) && !is_writable($this->logFilePath[$optIndex])) {
                    throw new RuntimeException('The file could not be written to. Check that appropriate permissions have been set.');
                }
                $this->setFileHandle('a', $optIndex);
            }
        } else {
            $this->setLogFilePath($logDirectory);
            if (file_exists($this->logFilePath['default']) && !is_writable($this->logFilePath['default'])) {
                throw new RuntimeException('The file could not be written to. Check that appropriate permissions have been set.');
            }
            $this->setFileHandle('a', 'default');
        }
    }

    /**
     * @param string $stdOutPath
     */
    public function setLogToStdOut($stdOutPath)
    {
        $this->logFilePath['default'] = $stdOutPath;
    }

    /**
     * @param string $logDirectory
     */
    public function setLogFilePath($logDirectory, $index = 'default', $suffix = '')
    {
        if ($this->options['filename']) {
            if (strpos($this->options['filename'], '.log') !== false || strpos($this->options['filename'], '.txt') !== false) {
                if ($suffix !== '') {
                    $pathinfoArray = pathinfo($this->options['filename']);
                    $this->logFilePath[$index] = $logDirectory.DIRECTORY_SEPARATOR.$pathinfoArray['dirname'].DIRECTORY_SEPARATOR.$pathinfoArray['filename'].$suffix.'.'.$pathinfoArray['extension'];
                } else {
                    $this->logFilePath[$index] = $logDirectory.DIRECTORY_SEPARATOR.$this->options['filename'];
                }
            } else {
                $this->logFilePath[$index] = $logDirectory.DIRECTORY_SEPARATOR.$this->options['filename'].$suffix.'.'.$this->options['extension'];
            }
        } else {
            $this->logFilePath[$index] = $logDirectory.DIRECTORY_SEPARATOR.$this->options['prefix'].date('Y-m-d').$suffix.'.'.$this->options['extension'];
        }
    }

    /**
     * @param $writeMode
     *
     * @internal param resource $fileHandle
     */
    public function setFileHandle($writeMode, $index = 'default')
    {
        $this->fileHandle[$index] = fopen($this->logFilePath[$index], $writeMode);
        if (! $this->fileHandle[$index]) {
            throw new RuntimeException("The '$index' log file could not be opened. Check permissions.");
        }
    }


    /**
     * Class destructor
     */
    public function __destruct()
    {
        foreach ($this->fileHandle as $fileHandle) {
            if ($fileHandle) {
                fclose($fileHandle);
            }
        }
    }

    /**
     * Sets the date format used by all instances of KLogger
     *
     * @param string $dateFormat Valid format string for date()
     */
    public function setDateFormat($dateFormat)
    {
        $this->options['dateFormat'] = $dateFormat;
    }

    /**
     * Sets the Log Level Threshold
     *
     * @param string $logLevelThreshold The log level threshold
     */
    public function setLogLevelThreshold($logLevelThreshold)
    {
        $this->logLevelThreshold = $logLevelThreshold;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = array())
    {
        if ($this->logLevels[$this->logLevelThreshold] < $this->logLevels[$level]) {
            return;
        }
        $index = 'default';
        if (!empty($this->options['fileGroups']) && is_array($this->options['fileGroups'])) {
            foreach ($this->options['fileGroups'] as $optIndex => $setting) {
                if (in_array($level, $setting['logLevels'])) {
                    $index = $optIndex;
                    break;
                }
            }
        }
        $message = $this->formatMessage($level, $message, $context);
        $this->write($message, $index);
    }

    /**
     * Writes a line to the log without prepending a status or timestamp
     *
     * @param string $message Line to write to the log
     * @return void
     */
    public function write($message, $index = 'default')
    {
        if (null !== $this->fileHandle[$index]) {
            if (fwrite($this->fileHandle[$index], $message) === false) {
                throw new RuntimeException('The file could not be written to. Check that appropriate permissions have been set.');
            } else {
                $this->lastLine = trim($message);
                $this->logLineCount++;

                if ($this->options['flushFrequency'] && $this->logLineCount % $this->options['flushFrequency'] === 0) {
                    fflush($this->fileHandle[$index]);
                }
            }
        }
    }

    /**
     * Get the file path that the log is currently writing to
     *
     * @return string
     */
    public function getLogFilePath($index = 'default')
    {
        return $this->logFilePath[$index];
    }

    /**
     * Get the last line logged to the log file
     *
     * @return string
     */
    public function getLastLogLine()
    {
        return $this->lastLine;
    }

    /**
     * Formats the message for logging.
     *
     * @param  string $level   The Log Level of the message
     * @param  string $message The message to log
     * @param  array  $context The context
     * @return string
     */
    protected function formatMessage($level, $message, $context)
    {
        if ($this->options['logFormat']) {
            $parts = array(
                'date'          => $this->getTimestamp(),
                'level'         => strtoupper($level),
                'level-padding' => str_repeat(' ', 9 - strlen($level)),
                'priority'      => $this->logLevels[$level],
                'message'       => $message,
                'context'       => json_encode($context),
            );
            $message = $this->options['logFormat'];
            foreach ($parts as $part => $value) {
                $message = str_replace('{'.$part.'}', $value, $message);
            }
        } else {
            $message = "[{$this->getTimestamp()}] [{$level}] {$message}";
        }

        if ($this->options['appendContext'] && ! empty($context)) {
            $message .= PHP_EOL.$this->indent($this->contextToString($context));
        }

        return $message.PHP_EOL;
    }

    /**
     * Gets the correctly formatted Date/Time for the log entry.
     *
     * PHP DateTime is dump, and you have to resort to trickery to get microseconds
     * to work correctly, so here it is.
     *
     * @return string
     */
    private function getTimestamp()
    {
        $originalTime = microtime(true);
        $micro = sprintf("%06d", ($originalTime - floor($originalTime)) * 1000000);
        $date = new DateTime(date('Y-m-d H:i:s.'.$micro, $originalTime));

        return $date->format($this->options['dateFormat']);
    }

    /**
     * Takes the given context and coverts it to a string.
     *
     * @param  array $context The Context
     * @return string
     */
    protected function contextToString($context)
    {
        $export = '';
        foreach ($context as $key => $value) {
            $export .= "{$key}: ";
            $export .= preg_replace(array(
                '/=>\s+([a-zA-Z])/im',
                '/array\(\s+\)/im',
                '/^  |\G  /m'
            ), array(
                '=> $1',
                'array()',
                '    '
            ), str_replace('array (', 'array(', var_export($value, true)));
            $export .= PHP_EOL;
        }
        return str_replace(array('\\\\', '\\\''), array('\\', '\''), rtrim($export));
    }

    /**
     * Indents the given string with the given indent.
     *
     * @param  string $string The string to indent
     * @param  string $indent What to use as the indent.
     * @return string
     */
    protected function indent($string, $indent = '    ')
    {
        return $indent.str_replace("\n", "\n".$indent, $string);
    }
}
