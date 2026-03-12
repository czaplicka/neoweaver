<?php
/**
 * Registers and fires all hooks for the plugin.
 *
 * @package NeoWeaver_WP_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NeoWeaver_Loader
 *
 * Collects action/filter registrations and bulk-registers them
 * via WordPress add_action() / add_filter().
 */
class NeoWeaver_Loader {

	/** @var array[] Registered actions. */
	private array $actions = [];

	/** @var array[] Registered filters. */
	private array $filters = [];

	/**
	 * Add an action to the collection.
	 *
	 * @param string $hook          The WordPress hook name.
	 * @param object $component     Object that owns the callback.
	 * @param string $callback      Method name on $component.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of args passed to callback.
	 */
	public function add_action(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Add a filter to the collection.
	 *
	 * @param string $hook          The WordPress hook name.
	 * @param object $component     Object that owns the callback.
	 * @param string $callback      Method name on $component.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Number of args passed to callback.
	 */
	public function add_filter(
		string $hook,
		object $component,
		string $callback,
		int $priority = 10,
		int $accepted_args = 1
	): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Register all collected hooks with WordPress.
	 */
	public function run(): void {
		foreach ( $this->actions as $action ) {
			add_action(
				$action['hook'],
				[ $action['component'], $action['callback'] ],
				$action['priority'],
				$action['accepted_args']
			);
		}

		foreach ( $this->filters as $filter ) {
			add_filter(
				$filter['hook'],
				[ $filter['component'], $filter['callback'] ],
				$filter['priority'],
				$filter['accepted_args']
			);
		}
	}
}
