<?php
if (!defined('ABSPATH')) exit;

/**
 * Collects actions/filters from every feature class and registers them with
 * WordPress in one pass. Feature classes stay hook-agnostic; EGGL_Core wires
 * them up through this registry.
 */
class EGGL_Loader {

    private $actions = [];
    private $filters = [];

    public function add_action($hook, $component, $method, $priority = 10, $accepted_args = 1) {
        $this->actions[] = compact('hook', 'component', 'method', 'priority', 'accepted_args');
    }

    public function add_filter($hook, $component, $method, $priority = 10, $accepted_args = 1) {
        $this->filters[] = compact('hook', 'component', 'method', 'priority', 'accepted_args');
    }

    public function run() {
        foreach ($this->filters as $f) {
            add_filter($f['hook'], [$f['component'], $f['method']], $f['priority'], $f['accepted_args']);
        }
        foreach ($this->actions as $a) {
            add_action($a['hook'], [$a['component'], $a['method']], $a['priority'], $a['accepted_args']);
        }
    }
}
