<?php
declare(strict_types=1);

namespace Pentagonal\ModularCLI\Util;

/**
 * Class Sanitizer
 * @package Pentagonal\ModularCLI\Util
 */
class Sanitizer
{
    /**
     * Fix Path Separator
     *
     * @param string $path
     * @param bool   $useCleanPrefix
     * @return string
     */
    public static function fixDirectorySeparator(string $path, $useCleanPrefix = false) : string
    {
        /**
         * Trimming path string
         */
        if (($path = trim($path)) == '') {
            return $path;
        }

        $path = preg_replace('`(\/|\\\)+`', DIRECTORY_SEPARATOR, $path);
        if ($useCleanPrefix) {
            $path = DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    /**
     * Normalize a filesystem path.
     *
     * @param string $path Path to normalize.
     * @return string Normalized path.
     */
    public static function normalizePath($path) : string
    {
        $path = self::fixDirectorySeparator($path);
        $path = preg_replace('|(?<=.)/+|', DIRECTORY_SEPARATOR, $path);
        if (':' === substr($path, 1, 1)) {
            $path = ucfirst($path);
        }

        if (self::isAbsolutePath($path) && strpos($path, '.')) {
            $explode = explode(DIRECTORY_SEPARATOR, $path);
            $array = [];
            foreach ($explode as $key => $value) {
                if ('.' == $value) {
                    continue;
                }
                if ('..' == $value) {
                    array_pop($array);
                } else {
                    $array[] = $value;
                }
            }

            $path = implode(DIRECTORY_SEPARATOR, $array);
        }

        return $path;
    }

    /**
     * Entities the Multi bytes deep string
     *
     * @param mixed $mixed  the string to detect multi bytes
     * @param bool  $entity true if want to entity the output
     *
     * @return mixed
     */
    public static function multiByteEntities($mixed, $entity = false)
    {
        static $hasIconV;
        static $limit;
        if (!isset($hasIconV)) {
            // safe resource check
            $hasIconV = function_exists('iconv');
        }

        if (!isset($limit)) {
            $limit = @ini_get('pcre.backtrack_limit');
            $limit = ! is_numeric($limit) ? 4096 : abs($limit);
            // minimum regex is 512 byte
            $limit = $limit < 512 ? 512 : $limit;
            // limit into 40 KB
            $limit = $limit > 40960 ? 40960 : $limit;
        }

        if (! $hasIconV && ! $entity) {
            return $mixed;
        }

        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::multiByteEntities($value, $entity);
            }
        } elseif (is_object($mixed)) {
            foreach (get_object_vars($mixed) as $key => $value) {
                $mixed->{$key} = self::multiByteEntities($value, $entity);
            }
        } /**
         * Work Safe with Parse @uses @var $limit Bit
         * | 4KB data split for regex callback & safe memory usage
         * that maybe fail on very long string
         */
        elseif (strlen($mixed) > $limit) {
            return implode('', self::multiByteEntities(str_split($mixed, $limit), $entity));
        }

        if ($entity) {
            $mixed = htmlentities(html_entity_decode($mixed));
        }

        return $hasIconV
            ? (
            preg_replace_callback(
                '/[\x{80}-\x{10FFFF}]/u',
                function ($match) {
                    $char = current($match);
                    $utf = iconv('UTF-8', 'UCS-4//IGNORE', $char);
                    return sprintf("&#x%s;", ltrim(strtolower(bin2hex($utf)), "0"));
                },
                $mixed
            ) ?:$mixed
            ) : $mixed;
    }

    /* --------------------------------------------------------------------------------*
     |                              Serialize Helper                                   |
     |                                                                                 |
     | Custom From WordPress Core wp-includes/functions.php                            |
     |---------------------------------------------------------------------------------|
     */

    /**
     * Check value to find if it was serialized.
     * If $data is not an string, then returned value will always be false.
     * Serialized data is always a string.
     *
     * @param  mixed $data   Value to check to see if was serialized.
     * @param  bool  $strict Optional. Whether to be strict about the end of the string. Defaults true.
     * @return bool  false if not serialized and true if it was.
     */
    public static function isSerialized($data, $strict = true)
    {
        /* if it isn't a string, it isn't serialized
         ------------------------------------------- */
        if (! is_string($data) || trim($data) == '') {
            return false;
        }

        $data = trim($data);
        // null && boolean
        if ('N;' == $data || $data == 'b:0;' || 'b:1;' == $data) {
            return true;
        }

        if (strlen($data) < 4 || ':' !== $data[1]) {
            return false;
        }

        if ($strict) {
            $last_char = substr($data, -1);
            if (';' !== $last_char && '}' !== $last_char) {
                return false;
            }
        } else {
            $semicolon = strpos($data, ';');
            $brace     = strpos($data, '}');

            // Either ; or } must exist.
            if (false === $semicolon && false === $brace
                || false !== $semicolon && $semicolon < 3
                || false !== $brace && $brace < 4
            ) {
                return false;
            }
        }

        $token = $data[0];
        switch ($token) {
            /** @noinspection PhpMissingBreakStatementInspection */
            case 's':
                if ($strict) {
                    if ('"' !== substr($data, -2, 1)) {
                        return false;
                    }
                } elseif (false === strpos($data, '"')) {
                    return false;
                }
            // or else fall through
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'i':
            case 'd':
                $end = $strict ? '$' : '';
                return (bool) preg_match("/^{$token}:[0-9.E-]+;$end/", $data);
        }

        return false;
    }

    /**
     * Un-serialize value only if it was serialized.
     *
     * @param  string $original Maybe un-serialized original, if is needed.
     * @return mixed  Un-serialized data can be any type.
     */
    public static function maybeUnSerialize($original)
    {
        if (! is_string($original) || trim($original) == '') {
            return $original;
        }

        /**
         * Check if serialized
         * check with trim
         */
        if (self::isSerialized($original)) {
            /**
             * use trim if possible
             * Serialized value could not start & end with white space
             */
            return @unserialize(trim($original));
        }

        return $original;
    }

    /**
     * Serialize data, if needed. @uses for ( un-compress serialize values )
     * This method to use safe as save data on database. Value that has been
     * Serialized will be double serialize to make sure data is stored as original
     *
     *
     * @param  mixed $data Data that might be serialized.
     * @return mixed A scalar data
     */
    public static function maybeSerialize($data)
    {
        if (is_array($data) || is_object($data)) {
            return @serialize($data);
        }

        // Double serialization is required for backward compatibility.
        if (self::isSerialized($data, false)) {
            return serialize($data);
        }

        return $data;
    }

    /**
     * Test if a give filesystem path is absolute.
     *
     * For example, '/foo/bar', or 'c:\windows'.
     *
     * @since 2.5.0
     *
     * @param string $path File path.
     * @return bool True if path is absolute, false is not absolute.
     */
    public static function isAbsolutePath($path) : bool
    {
        /*
         * This is definitive if true but fails if $path does not exist or contains
         * a symbolic link.
         */
        if (realpath($path) == $path) {
            return true;
        }

        if (strlen($path) == 0 || $path[0] == '.') {
            return false;
        }

        // Windows allows absolute paths like this.
        if (preg_match('#^[a-zA-Z]:\\\\#', $path)) {
            return true;
        }

        // A path starting with / or \ is absolute; anything else is relative.
        return ( $path[0] == '/' || $path[0] == '\\' );
    }
}
