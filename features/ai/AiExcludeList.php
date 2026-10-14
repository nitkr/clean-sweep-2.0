<?php
/**
 * Operator skip prefixes. A path outside the site root is rejected.
 */
final class AiExcludeList {

    private AiStore $store;
    private string $root;
    /** @var string[]|null */
    private ?array $memory = null;

    public function __construct(?AiStore $store = null, string $root = '') {
        $this->store = $store ?: new AiStore();
        $this->root = $root;
    }

    /** @param string[] $prefixes */
    public function usePrefixes(array $prefixes): void {
        $clean = [];
        foreach ($prefixes as $p) {
            if (is_string($p) && $p !== '') {
                $clean[] = $this->normalize($p);
            }
        }
        $this->memory = $clean;
    }

    /** @return string[] */
    public function all(): array {
        if ($this->memory !== null) {
            return $this->memory;
        }
        $data = $this->store->readJson('excludes.json', ['prefixes' => []]);
        $list = $data['prefixes'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $p) {
            if (is_string($p) && $p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * @return array{ok:bool,error?:string,code?:string,prefixes?:string[]}
     */
    public function add(string $prefix): array {
        $prefix = $this->normalize($prefix);
        if ($prefix === '') {
            return ['ok' => false, 'error' => 'Enter a file or folder under the site.', 'code' => 'EMPTY_PREFIX'];
        }
        if ($this->root !== '' && !AiPaths::insideRoot($prefix, $this->root)) {
            return ['ok' => false, 'error' => 'That path is outside the site.', 'code' => 'OUTSIDE_ROOT'];
        }
        $all = $this->all();
        if (!in_array($prefix, $all, true)) {
            $all[] = $prefix;
        }
        $this->store->writeJson('excludes.json', ['prefixes' => $all]);
        return ['ok' => true, 'prefixes' => $all];
    }

    /** @return string[] */
    public function remove(string $prefix): array {
        $prefix = $this->normalize($prefix);
        $all = array_values(array_filter($this->all(), static function ($p) use ($prefix) {
            return $p !== $prefix;
        }));
        $this->store->writeJson('excludes.json', ['prefixes' => $all]);
        return $all;
    }

    public function matches(string $rel): bool {
        $rel = ltrim(AiPaths::norm($rel), '/');
        foreach ($this->all() as $prefix) {
            if ($rel === $prefix || strncasecmp($rel, rtrim($prefix, '/') . '/', strlen(rtrim($prefix, '/')) + 1) === 0) {
                return true;
            }
        }
        return false;
    }

    private function normalize(string $prefix): string {
        $prefix = ltrim(AiPaths::norm(trim($prefix)), '/');
        return rtrim($prefix, '/');
    }
}
