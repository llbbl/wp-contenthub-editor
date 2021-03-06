<?php

namespace Bonnier\WP\ContentHub\Editor\Models;

use Bonnier\WP\ContentHub\Editor\Http\Redirects;
use Bonnier\WP\ContentHub\Editor\Models\ACF\Composite\CompositeFieldGroup;
use Bonnier\WP\ContentHub\Editor\Models\ACF\Composite\MagazineFieldGroup;
use Bonnier\WP\ContentHub\Editor\Models\ACF\Composite\MetaFieldGroup;
use Bonnier\WP\ContentHub\Editor\Models\ACF\Composite\TaxonomyFieldGroup;
use Bonnier\WP\ContentHub\Editor\Models\ACF\Composite\TeaserFieldGroup;
use Bonnier\WP\ContentHub\Editor\Models\ACF\Composite\TranslationStateFieldGroup;
use Bonnier\WP\ContentHub\Editor\Plugin;
use Bonnier\WP\ContentHub\Editor\Repositories\Scaphold\CompositeRepository;
use Bonnier\WP\ContentHub\Editor\Repositories\SiteManager\SiteRepository;
use WP_Post;
use Bonnier\WP\ContentHub\Editor\Helpers\SlugHelper;

/**
 * Class WpComposite
 *
 * @package \Bonnier\WP\ContentHub\Editor\Models
 */
class WpComposite
{
    const POST_TYPE = 'contenthub_composite';
    const POST_TYPE_NAME = 'Content';
    const POST_TYPE_NAME_SINGULAR = 'Composite';
    const POST_SLUG = '%category%';
    const POST_META_CONTENTHUB_ID = 'contenthub_id';
    const POST_META_CUSTOM_PERMALINK = 'custom_permalink';
    const POST_META_TITLE = '_yoast_wpseo_title';
    const POST_META_DESCRIPTION = '_yoast_wpseo_metadesc';
    const POST_CANONICAL_URL = '_yoast_wpseo_canonical';
    const POST_FACEBOOK_TITLE = '_yoast_wpseo_opengraph-title';
    const POST_FACEBOOK_DESCRIPTION = '_yoast_wpseo_opengraph-description';
    const POST_FACEBOOK_IMAGE = '_yoast_wpseo_opengraph-image';

    /**
     * Register the composite as a custom wp post type
     */
    public static function register() {

        static::register_permalink();

        add_action('init', function() {
            register_post_type(static::POST_TYPE,
                [
                    'labels' => [
                        'name' => __(static::POST_TYPE_NAME),
                        'singular_name' => __(static::POST_TYPE_NAME_SINGULAR)
                    ],
                    'public' => true,
                    'rewrite' => [
                        'slug' => static::POST_SLUG,
                    ],
                    'has_archive' => false,
                    'supports' => [
                        'title',
                        'author'
                    ],
                    'taxonomies' => [
                        'category'
                    ],
                ]
            );
            static::register_acf_fields();
        });

        add_action( 'save_post', [__CLASS__, 'on_save'], 10, 2 );
    }

    /**
     * @param $id
     *
     * @return null|string
     */
    public static function id_from_contenthub_id($id) {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare("SELECT post_id FROM wp_postmeta WHERE meta_key=%s AND meta_value=%s", static::POST_META_CONTENTHUB_ID, $id)
        );
    }

    private static function register_acf_fields() {
        CompositeFieldGroup::register();
        MagazineFieldGroup::register();
        MetaFieldGroup::register();
        TeaserFieldGroup::register();
        TranslationStateFieldGroup::register();
        TaxonomyFieldGroup::register(WpTaxonomy::get_custom_taxonomies());
    }

    private static function register_permalink() {

        // Add a rewrite rule to make post type unique
        add_action('registered_post_type', function($postType, $args){
            if($postType === static::POST_TYPE) {
                add_rewrite_rule(
                    '(.*)\/([^\/]+)\/?',
                    'index.php?category_name=$matches[1]&name=$matches[2]',
                    'bottom'
                );
                static::flush_rewrite_rules_if_needed();
            }
        }, 1, 2);
        
        //check if a page matches
        add_action( 'parse_request', function ( $request ) {
            // if the rule was matched, the query var will be set
            if( isset( $request->query_vars['category_name'] ) ){

                // check if a page exists, reset query vars to load that page if it does
                if( get_page_by_path( $request->query_vars['category_name'] ) ){
                    $request->query_vars['pagename'] = $request->query_vars['category_name'];
                    unset( $request->query_vars['category_name'] );
                }

                /*
                 * The above page check would have been applied and unset the category name, therefore we need to check again to avoid unwanted
                 * undefined index errors.
                 * The bellow 'hack' will be applied to category pages to make sure we can't access sub-category URL directly without the parent-category
                 * E.g:
                 * - http://gds.dev/terrasse/ (terrasse is the parent). Url works
                 * - http://gds.dev/terrasse/fliseterrasse/ (fliseterrasse sub-category). Url works
                 * - http://gds.dev/fliseterrasse/ (This should not work and throw 404 page)
                 */
                if ( isset($request->query_vars['category_name']) ) {
                    $parentCategory = get_category_by_slug($request->query_vars['category_name']);
                    if (isset($parentCategory->parent) && $parentCategory->parent > 0) {
                        add_action('wp',   function() {
                            global $wp_query;
                            $wp_query->is_404 = true;
                        });
                    }
                }

                // if the Contenthub Editor rewrite rule has caught a robots.txt request, then serve robots.txt
                if ( isset($request->query_vars['category_name']) && $request->query_vars['category_name'] == "robots.txt") {
                    unset( $request->query_vars['category_name'] );
                    $request->query_vars['robots'] = 1;
                }
            }
            return $request;
        },1 );

        /**
         * Have WordPress match postname to any of our public post types (post, page, contenthub_composite)
         * All of our public post types can have /post-name/ as the slug, so they better be unique across all posts
         * By default, core only accounts for posts and pages where the slug is /post-name/
         */

        add_action( 'pre_get_posts', function ($query) {

            if(is_admin() || !$query->is_main_query()) {
                return;
            }

            $query->set( 'post_type', [ 'post', 'page', static::POST_TYPE ] );

            return $query;
        });


        /**
         * Prepend categories to the post permalink
         */
        add_filter( 'post_type_link', function($postLink, $post) {

            if ( is_object( $post ) && $post->post_type === static::POST_TYPE && $post->post_status === 'publish') {

                $terms = wp_get_object_terms( $post->ID, 'category' );

                if( empty($terms) ) { // no category attached we mus use the default url generated by WordPress
                    return str_replace( static::POST_SLUG, static::POST_TYPE, $postLink );
                }


                $category = $terms[0];
                $slugs = collect([]);
                $hasParent = true;
                while ($hasParent) {
                    $slugs->push($category->slug);
                    if ($category->parent === 0) {
                        $hasParent = false;
                    } else {
                        $category = get_term($category->parent);
                    }
                }

                $tempUrlWithoutSlug = str_replace( static::POST_SLUG, $slugs->reverse()->implode('/'), $postLink );

                return rtrim(str_replace('%'.static::POST_TYPE.'%', $post->post_name, $tempUrlWithoutSlug), '/');

            }

            return $postLink;

        }, 1, 3 );
    }

    public static function on_save($postId, WP_Post $post) {
        if(is_object( $post ) && $post->post_type === static::POST_TYPE && $post->post_status !== 'auto-draft') {

            $contentHubId = get_post_meta($postId, static::POST_META_CONTENTHUB_ID, true);
            $action = !$contentHubId ? 'create' : 'update';

            $input = array_merge([
                'title' => $post->post_title,
                'kind' => get_field('kind', $postId),
                'status' => collect([
                    'publish' => 'Published',
                    'draft' => 'Draft',
                    'pending' => 'Ready'
                ])->get($post->post_status, 'Draft'),
            ], $action === 'update' ? ['id' => $contentHubId] : []);

            update_post_meta($postId, WpComposite::POST_META_CONTENTHUB_ID, CompositeRepository::{$action}($input));
        }
    }

    public static function map_all($callback) {
        $args = [
            'post_type' => static::POST_TYPE,
            'posts_per_page' => 100,
            'paged' => 0
        ];

        $posts = query_posts($args);

        while ($posts) {
            collect($posts)->each(function (WP_Post $post) use($callback){
                $callback($post);
            });

            $args['paged']++;
            $posts = query_posts($args);
        }
    }

    private static function flush_rewrite_rules_if_needed() {
        if ( get_option( Plugin::FLUSH_REWRITE_RULES_FLAG ) ) {
            flush_rewrite_rules();
            delete_option( Plugin::FLUSH_REWRITE_RULES_FLAG );
        }
    }
}
