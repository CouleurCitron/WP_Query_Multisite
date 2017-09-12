<?php

/**
 * Class WP_Query_Multisite
 */
class WP_Query_Multisite {

    /**
     * @var array
     */
    protected $ms_select = [];

    /**
     * @var array
     */
    protected $sites_to_query = [];

    /**
     * WP_Query_Multisite constructor.
     */
    public function __construct() {
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_action( 'pre_get_posts', [ $this, 'pre_get_posts' ], PHP_INT_MAX );
        add_filter( 'posts_clauses', [ $this, 'posts_clauses' ], 10, 2 );
        add_filter( 'posts_request', [ $this, 'posts_request' ], 10, 2 );
    }

    /**
     * @param $vars
     *
     * @return array
     */
    public function query_vars( $vars ) {
        $vars[] = 'multisite';
        $vars[] = 'sites__not_in';
        $vars[] = 'sites__in';

        return $vars;
    }

    /**
     * @param WP_Query $query
     */
    public function pre_get_posts( WP_Query $query ) {
        if ( $query->get( 'multisite' ) ) {

            global $wpdb, $blog_id;

            $site_IDs = $wpdb->get_col( "select blog_id from $wpdb->blogs" );

            if ( $query->get( 'sites__not_in' ) ) {
                foreach ( $site_IDs as $key => $site_ID ) {
                    if ( in_array( $site_ID, $query->get( 'sites__not_in' ) ) ) {
                        unset( $site_IDs[ $key ] );
                    }
                }
            }

            if ( $query->get( 'sites__in' ) ) {
                foreach ( $site_IDs as $key => $site_ID ) {
                    if ( ! in_array( $site_ID, $query->get( 'sites__in' ) ) ) {
                        unset( $site_IDs[ $key ] );
                    }
                }
            }

            $site_IDs = array_values( $site_IDs );

            $this->sites_to_query = $site_IDs;
        }
    }

    public function posts_clauses( $clauses, $query ) {
        if ( $query->get( 'multisite' ) ) {
            global $wpdb;

            // Start new mysql selection to replace wp_posts on posts_request hook
            $this->ms_select = [];

            $root_site_db_prefix = $wpdb->prefix;
            foreach ( $this->sites_to_query as $site_ID ) {

                switch_to_blog( $site_ID );

                $ms_select = $clauses['join'] . ' WHERE 1=1 ' . $clauses['where'];

                if ( $clauses['groupby'] ) {
                    $ms_select .= ' GROUP BY ' . $clauses['groupby'];
                }

                $ms_select = str_replace( $root_site_db_prefix, $wpdb->prefix, $ms_select );
                $ms_select = " SELECT $wpdb->posts.*, '$site_ID' as site_ID FROM $wpdb->posts $ms_select ";

                $this->ms_select[] = $ms_select;

                restore_current_blog();

            }

            // Clear join, where and groupby to populate with parsed ms select on posts_request hook;
            $clauses['join']    = '';
            $clauses['where']   = '';
            $clauses['groupby'] = '';

            // Orderby for tables (not wp_posts)
            $clauses['orderby'] = str_replace( $wpdb->posts, 'tables', $clauses['orderby'] );

        }

        return $clauses;
    }

    public function posts_request( $sql, $query ) {

        if ( $query->get( 'multisite' ) ) {

            global $wpdb;

            // Clean up remanescent WHERE request
            $sql = str_replace( 'WHERE 1=1', '', $sql );

            // Multisite request
            $sql = str_replace( "$wpdb->posts.* FROM $wpdb->posts",
                'tables.* FROM ( ' . implode( " UNION ", $this->ms_select ) . ' ) tables', $sql );

        }

        return $sql;
    }
}