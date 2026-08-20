<?php
/**
 * Visit samples, CS actions, and unexpected diffs (Slice 2).
 * Persists inside CleanSweep_VisitState JSON.
 */
final class CleanSweep_VisitStore {

    private CleanSweep_VisitState $state;

    public function __construct(?CleanSweep_VisitState $state = null) {
        $this->state = $state ?: new CleanSweep_VisitState();
    }

    public function state(): CleanSweep_VisitState {
        return $this->state;
    }

    public function record_action(string $type, string $detail = ''): void {
        $data = $this->state->load();
        if (!isset($data['actions']) || !is_array($data['actions'])) {
            $data['actions'] = [];
        }
        $data['actions'][] = [
            't' => time(),
            'type' => $type,
            'detail' => $detail,
        ];
        if (count($data['actions']) > 100) {
            $data['actions'] = array_slice($data['actions'], -100);
        }
        $this->state->save($data);
        if ($type !== 'seal_core') {
            $this->state->event('action:' . $type, $detail);
        }
    }

    /**
     * @param array<string,array> $samples path => sample
     */
    public function put_samples(string $bucket, array $samples, bool $replace = false): void {
        $data = $this->state->load();
        if (!isset($data['samples']) || !is_array($data['samples'])) {
            $data['samples'] = [];
        }
        if ($replace || !isset($data['samples'][$bucket])) {
            $data['samples'][$bucket] = [];
        }
        foreach ($samples as $path => $sample) {
            $data['samples'][$bucket][$path] = $sample;
        }
        $this->state->save($data);
    }

    /** @return array<string,array> */
    public function samples(string $bucket): array {
        $data = $this->state->load();
        $s = $data['samples'][$bucket] ?? [];
        return is_array($s) ? $s : [];
    }

    public function put_options(array $options): void {
        $data = $this->state->load();
        $prev = $data['options'] ?? [];
        $data['options'] = $options;
        $this->state->save($data);
        foreach ($options as $k => $v) {
            if (isset($prev[$k]) && $prev[$k] !== $v) {
                $this->state->event('unexpected:option', $k);
            }
        }
    }

    public function options(): array {
        $data = $this->state->load();
        return is_array($data['options'] ?? null) ? $data['options'] : [];
    }

    /**
     * Diff new samples against stored. First fill is not unexpected.
     *
     * @return array<int,array{path:string,reason:string,sample:array}>
     */
    public function diff_bucket(string $bucket, array $current): array {
        $data = $this->state->load();
        $ready = !empty($data['census_ready'][$bucket]);
        if (!$ready) {
            $this->put_samples($bucket, $current, true);
            $data = $this->state->load();
            if (!isset($data['census_ready']) || !is_array($data['census_ready'])) {
                $data['census_ready'] = [];
            }
            $data['census_ready'][$bucket] = true;
            $this->state->save($data);
            return [];
        }
        $prev = $this->samples($bucket);
        $out = [];
        foreach ($current as $path => $sample) {
            if (!isset($prev[$path])) {
                $out[] = ['path' => $path, 'reason' => 'created', 'sample' => $sample];
                continue;
            }
            $old = $prev[$path]['hash'] ?? null;
            $new = $sample['hash'] ?? null;
            if ($old && $new && $old !== $new) {
                $out[] = ['path' => $path, 'reason' => 'modified', 'sample' => $sample];
            }
        }
        $this->put_samples($bucket, $current, true);
        return $out;
    }

    public function add_unexpected(array $items): void {
        if ($items === []) {
            return;
        }
        $data = $this->state->load();
        if (!isset($data['unexpected']) || !is_array($data['unexpected'])) {
            $data['unexpected'] = [];
        }
        foreach ($items as $item) {
            $data['unexpected'][] = $item + ['t' => time()];
            $this->state->event('unexpected:' . ($item['reason'] ?? 'change'), $item['path'] ?? '');
        }
        if (count($data['unexpected']) > 80) {
            $data['unexpected'] = array_slice($data['unexpected'], -80);
        }
        $this->state->save($data);
    }

    public function unexpected(): array {
        $data = $this->state->load();
        return is_array($data['unexpected'] ?? null) ? $data['unexpected'] : [];
    }

    public function set_likely_source(?array $source): void {
        $this->state->merge(['likely_source' => $source]);
    }

    public function likely_source(): ?array {
        $data = $this->state->load();
        $s = $data['likely_source'] ?? null;
        return is_array($s) ? $s : null;
    }
}
