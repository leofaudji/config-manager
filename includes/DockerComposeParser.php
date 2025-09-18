<?php
/**
 * A dedicated, modern YAML parser for loading complex files like docker-compose.yml.
 * This parser is designed to correctly handle nested structures and indentation,
 * solving the "flattening" issue seen with simpler parsers.
 * It is used by the App Launcher feature to ensure compose files from Git are read correctly.
 * This class is separate from Spyc.php to avoid any potential impact on the Traefik YAML generator.
 */
class DockerComposeParser {

    private $lines = [];
    private $path = [];
    private $result = [];
    private $path_mod;

    /**
     * Public static method to load a YAML string or file.
     * @param string $input Path to YAML file or a string containing YAML.
     * @return array The parsed PHP array.
     */
    public static function YAMLLoad(string $input): array
    {
        $parser = new self();
        return $parser->_load($input);
    }

    /**
     * Internal loader.
     * @param string $input
     * @return array
     */
    private function _load(string $input): array
    {
        $this->path = [];
        $this->result = [];

        $lines = is_file($input) ? file($input) : explode("\n", $input);
        $lines = array_map([$this, 'chomp'], $lines);
        $this->lines = $lines;

        return $this->parse($this->lines);
    }

    /**
     * Main parsing loop.
     * @param array $lines
     * @param int $indent
     * @return array|string
     */
    private function parse(array &$lines, int $indent = -1)
    {
        $data = [];
        $line = array_shift($lines);

        while ($line !== null) {
            if ($this->isComment($line) || trim($line) === '') {
                $line = array_shift($lines);
                continue;
            }

            $this_indent = $this->getIndent($line);

            if ($this_indent <= $indent) {
                array_unshift($lines, $line);
                break;
            }

            if ($this->isArrayElement($line)) {
                $value = trim(substr($line, strpos($line, '-') + 1));
                // A list item is a map only if it contains a colon followed by a space.
                // This distinguishes `key: value` from unquoted strings like `8080:80`.
                if (strpos($value, ': ') !== false) {
                    $data[] = $this->parseHashElement($value, $this_indent);
                } else {
                    $data[] = $this->parseLiteral($value);
                }
            } elseif ($this->isHashElement($line)) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if ($value === '|' || $value === '>') {
                    $data[$key] = $this->literalBlock($lines, $this_indent + 2);
                } elseif (empty($value)) {
                    $data[$key] = $this->parse($lines, $this_indent);
                } else {
                    $data[$key] = $this->parseLiteral($value);
                }
            }

            $line = array_shift($lines);
        }

        return $data;
    }

    private function parseHashElement(string $line, int $indent)
    {
        list($key, $value) = explode(':', $line, 2);
        $key = trim($key);
        $value = trim($value);
        return [$key => $this->parseLiteral($value)];
    }

    private function parseLiteral($literal)
    {
        if (is_string($literal)) {
            $literal = trim($literal);
        }

        if (in_array(strtolower($literal), ['true', 'on', 'yes'])) return true;
        if (in_array(strtolower($literal), ['false', 'off', 'no'])) return false;
        if (in_array(strtolower($literal), ['null', '~', ''])) return null;
        if ($literal === '{}') return [];
        if ($literal === '[]') return [];

        if (is_numeric($literal)) {
            return strpos($literal, '.') !== false || stripos($literal, 'e') !== false ? (float)$literal : (int)$literal;
        }

        if (str_starts_with($literal, '"') && str_ends_with($literal, '"')) return substr($literal, 1, -1);
        if (str_starts_with($literal, "'") && str_ends_with($literal, "'")) return substr($literal, 1, -1);

        return (string)$literal;
    }

    private function literalBlock(array &$lines, int $baseIndent): string
    {
        $block = [];
        while (($line = current($lines)) !== false) {
            $indent = $this->getIndent($line);
            if ($indent < $baseIndent && trim($line) !== '') {
                break;
            }
            $block[] = substr($line, $baseIndent);
            array_shift($lines);
        }
        return implode("\n", $block);
    }

    private function getIndent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line));
    }

    private function isComment(string $line): bool
    {
        return str_starts_with(ltrim($line), '#');
    }

    private function isArrayElement(string $line): bool
    {
        return str_starts_with(ltrim($line), '-');
    }

    private function isHashElement(string $line): bool
    {
        return strpos($line, ':') !== false;
    }

    private function chomp(string $line): string
    {
        return rtrim($line, "\r");
    }
}

?>