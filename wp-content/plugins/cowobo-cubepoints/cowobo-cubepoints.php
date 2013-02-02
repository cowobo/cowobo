<?php
/*
  Plugin Name: CoWoBo CubePoints
  Plugin URI: http://cowobo.org
  Description: Another CoWoBo Module! Allows for easy enabling and disabling of CubePoints integration for Cowobo
  Version: 0.1
  Author: Cowobo
  Author URI: http://cowobo.org
 */

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

/**
 * Version number
 *
 * @since 0.1
 */
define('COWOBO_CP_VERSION', '0.1');

/**
 * PATHs and URLs
 *
 * @since 0.1
 */
define('COWOBO_CP_DIR', plugin_dir_path(__FILE__));
define('COWOBO_CP_URL', plugin_dir_url(__FILE__));
define('COWOBO_CP_INC_URL', COWOBO_CP_URL . '_inc/');

require_once ( COWOBO_CP_DIR . 'lib/widgets.php' );

if (!class_exists('CoWoBo_CubePoints')) :

    /**
     * @todo Create our own cubepoints based on profiles with postmeta instead of users
     */
    class CoWoBo_CubePoints    {

        public $ranks = array();
        public $points_config = array();
        private $config = array();
        private $override_points = array();

        public $current_user_points     = 0;
        public $current_user_rank       = array( "rank" => "", "points" => "");
        public $current_user_next_rank  = array( "rank" => "", "points" => "");

        public $displayed_user_points   = 0;
        public $displayed_user_rank     = array( "rank" => "", "points" => "");
        public $displayed_user_next_rank
                                        = array( "rank" => "", "points" => "");

        /**
         * Creates an instance of the CoWoBo_CubePoints class
         *
         * @return CoWoBo_CubePoints object
         * @since 0.1
         * @static
        */
        public static function &init() {
            static $instance = false;

            if (!$instance) {
                $instance = new CoWoBo_CubePoints;
            }

            return $instance;
        }

        /**
         * Constructor
         *
         * @since 0.1
         */
        public function __construct() {
            if ( !defined ( 'CP_VER' ) ) {
                cowobo()->do_admin_notice( "CubePoints plugin not active. To use CoWoBo CubePoints integration, enable CubePoints (as would seem quite logical to do..)" );
                return;
            }

            /**
             * Bogfix!
             */
            add_filter ( 'update_user_metadata', array ( &$this, 'bogfix_user_to_profile' ), 10, 4 );

            cowobo()->points = &$this;
            $this->set_points_config();

            $this->setup_context();

            if ( is_user_logged_in() ) {

                add_action ( 'wp', array ( &$this, '_maybe_give_kudos' ) );
                $this->setup_current_user();
                $this->logged_in_templates();
                $this->record_actions();

                add_action ( 'cp_log', array ( &$this, 'add_notification' ), 10, 4);
            }

            add_action ( 'cowobo_after_layouts', array ( &$this, 'do_post_points'), 10, 3 );
            add_action ('cowobo_logs_description', array ( &$this, 'cp_logs_desc' ), 10, 4);
            add_filter ( 'cp_post_points', array ( &$this, 'no_points_for_profiles' ), 10, 1 );

        }

            /**
             * PHP4
             *
             * @since 0.1
             */
            public function CoWoBo_CubePoints() {
                $this->__construct();
            }

        private function set_points_config() {
            $this->config = (object) parse_ini_file ( COWOBO_CP_DIR . 'config.ini', true );
            $points_config = $this->points_config = parse_ini_file ( COWOBO_CP_DIR . 'points.ini', true );

            // Override CPs default points where necessary
            foreach ( $points_config as $config ) {
                if ( array_key_exists( 'override',  $config ) && $hook = $config['override'] ) {
                    $this->override_points[$hook] = $config['points'];
                    add_action ( $hook, array ( &$this, 'return_override_points' ) );
                }
            }
        }

        public function return_override_points( $points ) {
            $hook = current_filter();
            if ( array_key_exists( $hook, $this->override_points ) )
                $points = $this->override_points[$hook];

            return $points;
        }

        /**
         * Bogfixing ftw!
         *
         * @param type $null
         * @param type $object_id
         * @param type $meta_key
         * @param type $meta_value
         * @return type
         */
        public function bogfix_user_to_profile( $null, $object_id, $meta_key, $meta_value ) {
            if ( $meta_key == 'cpoints' ) {
                $profile_id = cowobo()->users->get_user_profile_id( $object_id );
                update_post_meta ( $profile_id, 'cwb_points', $meta_value );
            }
            return $null;
        }

        private function record_actions() {
            $this->periodical_points();

            add_filter ( 'cowobo_post_updated', array ( &$this, 'record_post_edited' ), 10, 3 );
            add_action ( 'updated_user_meta', array ( &$this, '_maybe_has_updated_avatar' ), 10, 4 );
            add_action ( 'editrequest_accepted', array ( &$this, 'record_editrequest_accepted' ), 10, 4 );
            add_action ( 'cowobo_link_created', array ( &$this, 'record_link_created' ), 10, 2 );
        }

        private function logged_in_templates() {
            add_action ( 'current_user_box', array ( &$this, 'do_ranking_box' ) );
            add_action ( 'cowobo_after_post', array ( &$this, 'do_points_log_box_after_post'), 20, 3 );
            add_action ( 'cowobo_after_post', array ( &$this, 'do_post_kudos_box'), 30, 3 );

            add_action ( 'cowobo_after_searchbar', array ( &$this, 'do_your_score_box') );
            add_action ( 'cowobo_after_user_link', array ( &$this, 'do_userlink_score') );
        }

        public function record_link_created ( $post_id, $linked_post_id ) {
            $this->add_points( 'link_created', $post_id, 0, 0, array ( "secondary_postid" => $linked_post_id ) );
        }

        public function do_your_score_box() {
            echo '<li id="profilemenu" class="icon-heart">' . $this->current_user_points . ' ▼</li>';
        }

        public function do_userlink_score() {
            echo "<br><em>{$this->current_user_rank['rank']} <span class='icon-heart'>{$this->current_user_points}</span></em>";
        }

        public function add_points( $type, $post_id = 0, $data_user_id = 0, $recipient_id = 0, $data = '', $points = false ) {
            if ( ! $post_id ) $post_id = get_the_ID();
            if ( ! $recipient_id ) $recipient_id = get_current_user_id ();

            if ( ! is_array ( $data ) ) parse_str ( $data );

            if ( ! $points ) {
                if ( ! isset ( $this->points_config[ $type ] ) ) return false;
                $points = $this->points_config[$type]['points'];
            }

            $data['postid'] = $post_id;
            if ( $data_user_id ) $data['userid'] = $data_user_id;
            elseif ( $recipient_id != get_current_user_id() ) $data['userid'] = get_current_user_id();
            $data_str = http_build_query($data);

            cp_points( "cowobo_" . $type, $recipient_id, $points, $data_str );
        }

        public function _maybe_has_updated_avatar ( $meta_id, $object_id, $meta_key, $_meta_value ) {
            if ( $meta_key != 'simple_local_avatar' || empty ( $_meta_value ) ) return;

            if ( $this->is_recently_updated( 'cowobo_avatar_updated' ) ) return;

            //cp_points( 'cowobo_avatar_updated', get_current_user_id(), COWOBO_AVATAR_UPDATED_POINTS, "userid=" . get_current_user_id() );
            $this->add_points ( 'avatar_updated' );

        }

        public function no_points_for_profiles( $points ) {
            if ( cowobo()->query->confirm || cowobo()->query->postcat == get_cat_ID ( 'Coders' ) ) return 0;
            else return $points;
        }

        public function add_notification($type, $uid, $points, $data) {
            if (get_current_user_id() != $uid )
                return;

            if($points>0){
                $m= "+$points";
                cowobo()->add_notice( "Wow! That just got you $points extra points!" );
            } else {
                cowobo()->add_notice( "Careful, that action cost you $points!" );
            }

        }

        private function setup_context() {
            if( ! cp_module_activated('ranks') ) {
                cowobo()->do_admin_notice ( "Please activate CubePoints ranks module" );
                return;
            }

            $ranks = get_option('cp_module_ranks_data');
            krsort($ranks);
            $this->ranks = $ranks;
        }

        /**
         * Setup current user rank and next rank
         *
         * @return type
         */
        private function setup_current_user() {

            $current_user_points = $this->get_current_user_points();
            $current_user_ranks = $this->get_user_ranks_by_points( $current_user_points );

            $this->current_user_rank        = $current_user_ranks['current_rank'];
            $this->current_user_next_rank   = $current_user_ranks['next_rank'];

        }

        private function setup_displayed_user() {
            $display_user_id = cowobo()->users->displayed_user->ID;
            $displayed_user_points = $this->displayed_user_points = $this->get_user_points( $display_user_id );
            $displayed_user_ranks = $this->get_user_ranks_by_points( $displayed_user_points );

            $this->displayed_user_rank        = $displayed_user_ranks['current_rank'];
            $this->displayed_user_next_rank   = $displayed_user_ranks['next_rank'];
        }

            private function get_user_ranks_by_points ( $points ) {
                $previous_rank = '';
                foreach( $this->ranks as $p => $r ) {
                    if( $points >= $p ) {
                        $current_rank = array ( "rank" => $r, "points" => $p );
                        $next_rank = $previous_rank;
                        break;
                    }
                    $previous_rank = array ( "rank" => $r, "points" => $p );
                }

                if ( empty ( $next_rank ) ) $next_rank = $current_rank;

                return array (
                    "current_rank" => $current_rank,
                    "next_rank" => $next_rank,
                );
            }

        public function get_current_user_points() {
            if ( !is_user_logged_in() ) return null;

            if ( $this->current_user_points ) return $this->current_user_points;
            $this->current_user_points = $this->get_user_points( get_current_user_id() );
            return $this->current_user_points;
        }

        public function the_points( $uid = 0, $format = 1 ) {
            echo $this->get_user_points(get_current_user_id(), $format );
        }

            /**
             * Alias for cp_displayPoints
             */
            public function get_user_points( $uid = 0, $format = 0 ) {
                // First, check our cache for the current user (method will come back with UID if necessary)
                if ( ! $uid ) return $this->get_current_user_points();

                return cp_displayPoints( $uid, true, $format );
            }

        public function get_current_user_rank() {
            if ( !is_user_logged_in() ) return null;

            if ( $this->current_user_rank ) return $this->current_user_rank['rank'];
            $this->current_user_rank = $this->get_user_rank ( get_current_user_id() );
        }

        public function the_rank( $uid = 0 ) {
            echo $this->get_user_rank( $uid );
        }

            public function get_user_rank ( $uid = 0 ) {
                if ( !function_exists( 'cp_module_ranks_getRank' ) ) return;

                if ( ! $uid ) return $this->get_current_user_rank();

                return cp_module_ranks_getRank( $uid );
            }

        public function do_ranking_box() {
            echo "<div class='ranking-box'>";
                //echo "<p>You are a <strong>" . $this->get_current_user_rank() . "</strong> with " . $this->get_current_user_points() . " awesomeness.</p>";
                if ( $this->get_current_user_rank() != $this->current_user_next_rank['rank'] ) {
                    //echo "<p class='next-rank'>Next rank: <strong>{$this->current_user_next_rank['rank']}</strong> ({$this->current_user_next_rank['points']})";
                    $this->do_progression( $this->current_user_points, $this->current_user_rank, $this->current_user_next_rank );
                }
            echo "</div>";
        }

        /**
         * @todo make this work with different UIDs
         * @param type $uid
         */
        public function do_progression( $points, $current_rank, $next_rank ) {

            $current_points = (int) $points - (int) $current_rank['points'];
            $goal = (int) $next_rank['points'] - (int) $current_rank['points'];
            if ( $goal == 0 ) return false;
            $percentage = round ( ($current_points / $goal) * 100 );
            if ( $percentage < 5 ) $percentage = 100;

            $image_width = 50;
            $pixels = ( $image_width / 100 ) * $percentage;

            ?>
            <div class="points-progression-container" style="width:100px;display: inline-block;position:relative;height:15px">
                <div class="stat-bar" style="width:<?php echo $pixels ;?>px; overflow: hidden;height:15px;background:#ff0;">                </div>
                <img title="Another <?php echo $goal - $current_points; ?> points to become a <?php echo $next_rank['rank']; ?>!" src="<?php echo COWOBO_CP_INC_URL ?>progress_hearts.png" style="position:absolute;top:0;">
            </div>
            <?php
            return $goal - $current_points;

        }

        /**
         * Box to gives kudos to post authors for logged in users
         *
         * @param type $postid
         * @param type $postcat
         * @param type $author
         * @return type
         * @todo make it look nice
         */
        public function do_post_kudos_box ( $postid, $postcat, $author ) {
            // Authors don't need to see this
            if ( $author ) return;

            echo "<div class='tab'>";

                // Different box for profiles
                if ( $postcat->slug == 'coder' ) {
                    $kudos_link = add_query_arg( array ( 'profile_kudos' => wp_create_nonce( "profile_kudos_$postid" ) ) );
                    echo "<p>Do you like " . get_the_title() . "? Or do you endorse the work of " . get_the_title() . "? Show it by giving props and rescept!</p>";

                } else {
                    $kudos_link = add_query_arg( array ( 'post_kudos' => wp_create_nonce( "post_kudos_$postid" ) ) );
                    echo "<p>Liked this post? Give the authors some kudos! It's free!*</p>";
                }
                echo "<p><a href='$kudos_link' class='button'>Give kudos</a></p>";

            echo "</div>";
        }

        public function _maybe_give_kudos() {
            if ( ! is_single() ) return;

            $postid = get_the_ID();
            if ( cowobo()->query->post_kudos ) {
                $object = 'post';
                if ( ! wp_verify_nonce ( cowobo()->query->post_kudos, "post_kudos_$postid" ) )
                    return;
            } elseif ( cowobo()->query->profile_kudos ) {
                $object = 'profile';
                if ( ! wp_verify_nonce ( cowobo()->query->profile_kudos, "profile_kudos_$postid" ) )
                    return;
            } else {
                return;
            }

            // Don't allow authors to kudo themselves
            if ( cowobo()->posts->is_user_post_author( $postid ) )
                return;

            // Give kudos!
            $this->give_kudos( $object, $postid );
        }

            private function give_kudos ( $object = '', $object_id = 0, $amount = 0, $origin= '' ) {
                if ( empty ( $origin ) ) $origin = $object;

                switch ( $object ) {
                    case 'post' :
                        if ( ! $amount ) $amount = $this->points_config['kudos_post']['points'];

                        if ( $this->kudos_already_given() ) {
                            cowobo()->add_notice("Sorry, you have already shown your appreciation for this post before!");
                            return;
                        }

                        $this->add_post_likes ( $object_id );
                        $this->add_points( 'post_kudos_given' );

                        $authors = cowobo()->posts->get_post_authors( $object_id );
                        foreach ( $authors as $author_profile_id ) {
                            $this->give_kudos( 'profile', $author_profile_id, $amount, $origin );
                        }
                        break;
                    case 'profile' :
                        if ( $origin == $object ) {
                            if ($this->kudos_already_given() ) {
                                cowobo()->add_notice("You must really like this angel! Sorry, but you can't give props to the same person twice.");
                                return;
                            }
                            $this->add_points( 'profile_kudos_given' );
                        }
                        if ( ! $amount ) $amount = $this->points_config['kudos_profile']['points'];
                        $users = cowobo()->users->get_users_by_profile_id( $object_id );
                        foreach ( $users as $user ) {
                            $this->give_kudos ( 'user', $user->ID, $amount, $origin );
                        }
                        break;
                    case 'user' :
                        if ( ! $amount ) $amount = 1; // Unidentified kudos. Let's just give one anyway.

                        $this->add_points( "kudos_$origin", 0, 0, $object_id );
                        break;

                }
            }

            private function kudos_already_given ( $data = '' ) {
                global $wpdb;

                if ( empty ( $data) ) $data = "userid=" . get_current_user_id() . "&postid=" . get_the_ID();

                $query = $wpdb->prepare ( 'SELECT * FROM `' . CP_DB . '` WHERE data = %s', $data );
                $results = $wpdb->get_results( $query, 'ARRAY_A' );
                return ( ! empty ( $results ) );
            }

        /**
         * Add a like to a post
         * @param int (optional) $post_id
         * @param int (optional) $amount
         * @return bool
         */
        public function add_post_likes ( $post_id = 0, $amount = 1 ) {
            if ( ! $post_id ) $post_id = get_the_ID();
            if ( ! $post_id ) return;

            $old_amount = $this->get_post_likes ( $post_id );
            $new_amount = (int) $amount + $old_amount;
            return $this->set_post_likes( $post_id, $new_amount );
        }

            public function get_post_likes ( $post_id  ) {
                return (int) get_post_meta( $post_id, 'cwb_points', true );
            }

            public function set_post_likes ( $post_id, $new_amount = 0 ) {
                return update_post_meta( $post_id, 'cwb_points', $new_amount );
            }

        public function get_post_points ( $post_id ) {
            $likes = $this->get_post_likes( $post_id );
            $comments = get_comment_count( $post_id );
            return (int) $likes + (int) $comments['approved'];
        }

        public function cp_logs_desc( $type, $uid, $points, $data ){
            $points_config = $this->points_config;
            $user_profile = $post = false;
            $user_link = $post_link = $secondary_post_link = '';

            if ( substr ( $type, 0, 7 ) == 'cowobo_' ) {
                $type = substr ( $type, 7 );

                $data_arr = array();
                parse_str ( $data, $data_arr );
                /*if ( empty ( $data_arr ) ) {
                    echo "Corrupted data";
                    return;
                }*/

                if ( isset ( $data_arr['userid'] ) ) {
                    $user_profile = get_post( cowobo()->users->get_user_profile_id( $data_arr['userid'] ) );
                    $user_link = '<a href="'.get_permalink( $user_profile ).'">' . $user_profile->post_title . '</a>';
                } if ( isset ( $data_arr['postid'] ) ) {
                    $post = get_post( $data_arr['postid'] );
                    $post_link = '<a href="'.get_permalink( $post ).'">' . $post->post_title . '</a>';
                } if ( isset ( $data_arr['secondary_postid'] ) ) {
                    $secondary_post = get_post( $data_arr['secondary_postid'] );
                    $secondary_post_link = '<a href="'.get_permalink( $secondary_post ).'">' . $secondary_post->post_title . '</a>';
                }

                //if ( ! $user_profile && ! $post ) return;

            } else {
                // Fallback
                if ( ! array_key_exists ( $type, $points_config ) )
                    do_action('cp_logs_description', $type, $uid, $points, $data );

                if ( ! empty ( $data ) ) {
                    $post = get_post($data);
                    if ( is_a ( $post, 'WP_Post' ) )
                        $post_link = '<a href="'.get_permalink( $post ).'">' . $post->post_title . '</a>';
                }
            }

            if ( array_key_exists ( $type, $points_config ) ) {
                $message = str_replace (
                        array ( "%post%", '%post2%', "%user%"),
                        array ( $post_link, $secondary_post_link, $user_link ),
                        $points_config[$type]['message']
                    );
                echo $message;
            }

            return;
        }

        public function do_post_points ( $post_id, $postcat, $author ) {
            if ( cowobo()->users->is_profile() ) {

                $this->setup_displayed_user();

                echo ' <span class="field"><span class="hint icon-heart">' . $this->displayed_user_points . '</span></span>';
                echo ' <span class="field"><h3>Rank:</h3><span class="hint">' . $this->displayed_user_rank['rank'] . '</span></span>';
                echo ' <span class="field">';
                $this->do_progression( $this->displayed_user_points, $this->displayed_user_rank, $this->displayed_user_next_rank );
                echo '</span>';
            } else {
                echo ' <span class="field"><h3>Points:</h3><span class="hint">' . $this->get_post_points( $post_id ) . '</span></span>';
            }

        }

        public function do_points_log_box_after_post ( $postid, $postcat, $author ) {
            if ( ! cowobo()->users->is_profile() ) return;

            echo '<div class="tab">';
            echo '<h3>Activities</h3>';
            $this->do_points_log_box( cowobo()->users->displayed_user->ID, 15 );
            echo '</div>';
        }

        public function do_points_log_box( $uid = 0, $num_posts = 5 ) {
            if ( ! $uid )  $uid = get_current_user_id();

            $log = $this->get_log ( $uid, $num_posts );
            require ( COWOBO_CP_DIR . 'templates/log.php' );

        }

            private function get_log( $uid = 'all', $limit = 10 ) {
                global $wpdb;

                $q      = '';
                $limitq = '';

                if ( $uid && 'all' != $uid ) {
                    $q = $wpdb->prepare ( " WHERE `uid` = %d", $uid );
                }

                if( $limit > 0 )
                    $limitq = 'LIMIT '.(int) $limit;

                $query = 'SELECT * FROM `' . CP_DB . '` ' . $q . ' ORDER BY timestamp DESC ' . $limitq;
                return $wpdb->get_results( $query );
            }

        public function record_post_edited( $post_id ) {
            if ( $post_id == 0 ) return;

            global $wpdb;

            $type = '';
            if ( cowobo()->users->is_profile( $post_id ) )
                $type = "cowobo_profile_updated";
            else
                $type = "cowobo_post_updated";

            if ( $this->is_recently_updated ( $type ) ) {
                cowobo()->add_notice("That was fast! You know you won't get any points for updating so fast?");
                return;
            }

            $this->add_points ( $type, $post_id );
        }

        public function record_editrequest_accepted ( $rquser_profile_id, $rqpost ) {
            $rquser = cowobo()->users->get_users_by_profile_id ( $rquser_profile_id, true )->ID;
            // Give points to the accepter
            $this->add_points( 'editrequest_accepted', $rqpost, $rquser );
            // Give points to the accepted
            $this->add_points( 'your_editrequest_accepted', $rqpost, 0, $rquser );
        }

        public function is_recently_updated ( $type, $uid = 0 ) {
            global $wpdb;

            if ( ! $uid ) $uid = get_current_user_id ();
            if ( ! $uid ) return;

            $time = $this->config->intervals['updates'] * 60 * 60;
            $difference = time() - $time;

            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM ".CP_DB." WHERE `uid`=$uid AND `timestamp`>$difference AND `type`='$type'");
            return ( $count != 0 );
        }

        public function periodical_points() {
            global $wpdb;

            $uid = get_current_user_id();
            $time = $this->config->intervals['periodical_points'] * 60 * 60;
            $difference = time() - $time;

            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM ".CP_DB." WHERE `uid`=$uid AND `timestamp`>$difference AND `type`='cowobo_periodical'");
            if($count != 0 ) return;

            $this->add_points ( 'periodical' );
        }

        public function points_for ( $action ) {
            if (array_key_exists( $action, $this->points_config ) && array_key_exists( 'points', $this->points_config[$action] ) ) {
                return $this->points_config[$action]['points'];
            }
            return 0;
        }

        public function do_point_descriptions( $active = 'yes' ) {
            echo $this->get_point_descriptions( $active );
            if ( $active && $active != 'all') echo "<p class='hide-if-no-js'><a href='#' class='toggle-inactive-point-descs'>Show all ways to get points</a></p>";
        }

        public function get_point_descriptions( $active = 'yes' ) {
            $out = array();
            if ( $active == 'all' || $active == 'mixed' ) $active = false;
            foreach ( $this->points_config as $key => $config) {
                if ( $active && $config['active'] != $active ) continue;

                $points = $config['points'];
                if ( ! array_key_exists( $points, $out ) ) $out[$points] = array();

                $out[$points][] = "<div class='point-desc $key active-{$config['active']}'><span class='points-tag grey'><span class='icon-heart'>$points</span></span><p>{$config['description']}</p></div>";
            }
            krsort ( $out );

            foreach ( $out as &$output ) {
                $output = implode ( "\n", $output );
            }
            return implode ( "\n", $out );
        }

        public function get_recently_active_user_ids( $limit = 5, $with_timestamp = true ) {
            global $wpdb;

            if ( $with_timestamp ) {
                if ( ! $users = wp_cache_get( 'recently_active_users_with_timestamp', 'cowobo_cubepoints' ) ) {

                    $query = $wpdb->prepare ( "SELECT uid, max(timestamp) as maxtimestamp FROM `" . CP_DB . "` GROUP BY uid ORDER BY maxtimestamp DESC LIMIT %d", $limit );
                    $users = $wpdb->get_results( $query, ARRAY_A );

                    wp_cache_set( 'recently_active_users_with_timestamp', $users, 'cowobo_cubepoints' );
                }
            } else {
                if ( ! $users = wp_cache_get( 'recently_active_users', 'cowobo_cubepoints' ) ) {

                    $query = $wpdb->prepare ( "SELECT DISTINCT `uid` FROM `" . CP_DB . "` ORDER BY `id` DESC LIMIT %d", $limit );
                    $users = $wpdb->get_col( $query );

                    wp_cache_set( 'recently_active_users', $users, 'cowobo_cubepoints' );
                }
            }

            return $users;
        }

        public function get_recently_active_profile_ids ( $limit = 5, $with_timestamp = true ) {
            $user_ids = $this->get_recently_active_user_ids( $limit, $with_timestamp );
            if ( empty ( $user_ids ) ) return array();

            // Try for cache
            if ( $with_timestamp )
                $cached_users = wp_cache_get( 'recently_active_profiles_with_timestamp', 'cowobo_cubepoints' );
            else
                $cached_users = wp_cache_get( 'recently_active_profiles', 'cowobo_cubepoints' );
            if ( $cached_users ) return $cached_users;


            $profile_ids = array();
            foreach ( $user_ids as $user ) {
                if ( $with_timestamp ) {
                    $profile_ids[] = array_merge( $user, array ( 'profile_id' => cowobo()->users->get_user_profile_id( $user['uid'] ) ));
                } else {
                    $profile_ids[] = cowobo()->users->get_user_profile_id( $user );
                }
            }

            return $profile_ids;
        }

        public function get_user_last_activity( $userid, $formatted = true ) {
            global $wpdb;

            if ( ! $last_active = wp_cache_get ( "last_activity_$userid", 'cowobo_cubepoints' ) ) {
                $query = $wpdb->prepare ( 'SELECT timestamp FROM `' . CP_DB . '` WHERE uid = %d ORDER BY id DESC LIMIT 1', $userid );
                $last_active = current ( $wpdb->get_col ( $query ) );
                if ( empty ( $last_active ) ) return false;
                wp_cache_set ( "last_activity_$userid", $last_active, 'cowobo_cubepoints' );
            }
            if ( ! $formatted ) return $last_active;

            $time = cp_relativeTime( $last_active );
            return $time;
        }

            public function get_user_last_activity_by_profile ( $profile_id ) {
                $user = cowobo()->users->get_user_by_profile_id ( $profile_id );
                if ( ! is_a ( $user, "WP_User" ) ) return;
                return $this->get_user_last_activity( $user->ID );
            }

    }



    add_action('init', array('CoWoBo_CubePoints', 'init'));
endif;