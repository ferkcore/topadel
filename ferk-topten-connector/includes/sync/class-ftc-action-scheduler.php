<?php
/**
 * Wrapper around Action Scheduler or WP-Cron.
 *
 * @package Ferk_Topten_Connector\Includes\Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides queueing capabilities.
 */
class FTC_Action_Scheduler {
    /**
     * Schedule single action.
     *
     * @param int    $timestamp Timestamp.
     * @param string $hook      Hook name.
     * @param array  $args      Arguments.
     */
    public static function schedule_single( $timestamp, $hook, $args = array() ) {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( $timestamp, $hook, $args );
        } else {
            wp_schedule_single_event( $timestamp, $hook, $args );
        }
    }

    /**
     * Schedule immediate action.
     *
     * @param string $hook Hook name.
     * @param array  $args Arguments.
     */
    public static function schedule_immediate( $hook, $args = array() ) {
        self::schedule_single( time(), $hook, $args );
    }
}
