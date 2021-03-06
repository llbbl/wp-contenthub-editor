<?php

namespace Bonnier\WP\ContentHub\Editor\Models;

/**
 * Class WpAttachment
 *
 * @package \Bonnier\WP\ContentHub\Editor\Models
 */
class WpAttachment
{
    const POST_META_CONTENTHUB_ID = 'contenthub_id';
    const POST_META_COPYRIGHT = 'attachment_copyright';

    public static function register()
    {
        // Add custom copyright field to image attachments
        add_filter( 'attachment_fields_to_edit', [__CLASS__, 'add_copyright_field_to_media_uploader'], null, 2 );
        add_filter( 'attachment_fields_to_save', [__CLASS__, 'add_copyright_field_to_media_uploader_save'], null, 2 );

        // Make attachments private
        add_filter( 'wp_update_attachment_metadata', [__CLASS__, 'wp_update_attachment_metadata'], 1000, 2);
    }

    public static function wp_update_attachment_metadata($data, $postId)
    {
        $postMeta = get_post_meta($postId);

        // Check that attachment meta contains s3 info so we can set the visibility of the object
        if(isset($postMeta['amazonS3_info'][0]) && $s3Info = unserialize($postMeta['amazonS3_info'][0])){
            static::set_s3_object_visibility($s3Info['bucket'], $s3Info['key'], 'private');
        }

        return $data;
    }

    public static function wp_get_attachment_url($url, $post_id)
    {
        if(is_admin()) {
            // Create signed url that expires after 3600 seconds (1 hour)
            return as3cf_get_secure_attachment_url($post_id, 3600);
        }

        return $url;
    }

    /**
     * Adding a "Copyright" field to the media uploader $form_fields array
     *
     * @param array $form_fields
     * @param object $post
     *
     * @return array
     */
    public static function add_copyright_field_to_media_uploader( $form_fields, $post ) {
        // Only add copyright field to image attachments
        if(!str_contains($post->post_mime_type, 'image')) {
            return $form_fields;
        }
        $form_fields['copyright_field'] = [
            'label' => __('Copyright'),
            'input' => 'text',
            'value' => get_post_meta( $post->ID, static::POST_META_COPYRIGHT, true ),

        ];
        return $form_fields;
    }


    /**
     * Save our new "Copyright" field
     *
     * @param object $post
     * @param object $attachment
     *
     * @return array
     */
    public static function add_copyright_field_to_media_uploader_save( $post, $attachment ) {
        if ( isset($attachment['copyright_field']) && ! empty( $attachment['copyright_field'] ) )
            update_post_meta( $post['ID'], static::POST_META_COPYRIGHT, $attachment['copyright_field'] );
        else
            delete_post_meta( $post['ID'], static::POST_META_COPYRIGHT );

        return $post;
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

    /**
     * @param $postId
     *
     * @return null|string
     */
    public static function contenthub_id($postId) {
        return get_post_meta($postId, static::POST_META_CONTENTHUB_ID, true) ?: null;
    }

    /**
     * @param $id
     *
     * Deleted the attachment with matching contenthub id
     *
     * @return null|string
     */
    public static function delete_by_contenthub_id($id) {
        if($attachmentId = static::id_from_contenthub_id($id)) {
            return wp_delete_post($attachmentId, true);
        }
    }

    public static function upload_attachment($postId, $file) {

        if(is_null($file)) {
            return null;
        }


        // If attachment already exists then update meta data and return the id
        if($existingId = static::id_from_contenthub_id($file->id)) {
            static::update_attachment($existingId, $file);
            return $existingId;
        }

        // Make sure that url has a scheme
        if(!isset(parse_url($file->url)['scheme'])) {
            $file->url = 'http:' . $file->url;
        }

        $rawFileName = basename( $file->url );
        $fileName = sanitize_file_name($rawFileName); // Sanitize the new file name so WordPress will upload it

        // Make sure to sanitize the file name so urls with spaces and other special chars will work
        $file->url = str_replace($rawFileName, urlencode($rawFileName), $file->url);

        // Getting file stream
        if(!$fileStream = @file_get_contents( $file->url )) {
            if(!$fileStream = @file_get_contents( urldecode($file->url) )) // try the url decoded
            return null;
        }

        // Uploading file
        $uploadedFile = wp_upload_bits( $fileName, null, $fileStream );
        if ($uploadedFile['error']) {
            return null;
        }

        // Creating attachment
        $fileMeta = wp_check_filetype( $fileName, null );
        $attachment = [
            'post_mime_type' => $fileMeta['type'],
            'post_parent' => $postId,
            'post_title' => '',
            'post_content' => '',
            'post_excerpt' => $file->caption ?? '',
            'post_status' => 'inherit',
            'meta_input' => [
                static::POST_META_CONTENTHUB_ID => $file->id,
                static::POST_META_COPYRIGHT => $file->copyright ?? '',
                '_wp_attachment_image_alt' => $file->altText ?? '',
            ]
        ];
        $attachmentId = wp_insert_attachment( $attachment, $uploadedFile['file'], $postId );
        if (is_wp_error($attachmentId)) {
            return null;
        }

        // Attachment meta data
        require_once(ABSPATH . "wp-admin" . '/includes/image.php');
        $attachmentData = wp_generate_attachment_metadata( $attachmentId, $uploadedFile['file'] );
        if(!wp_update_attachment_metadata( $attachmentId,  $attachmentData )) {
            return null;
        }

        if(function_exists('pll_set_post_language')){
            pll_set_post_language($attachmentId, pll_get_post_language($postId));
        }

        return $attachmentId;
    }

    private static function update_attachment($attachmentId, $file) {
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $file->altText ?? '');
        update_post_meta($attachmentId, static::POST_META_COPYRIGHT, $file->copyright ?? '');
        global $wpdb;
        $wpdb->update('wp_posts', [
            'post_title' => $file->caption ?? '',
            'post_content' => '',
            'post_excerpt' => $file->caption ?? '',
        ],
        [
            'ID' => $attachmentId
        ]);
    }

    private static function set_s3_object_visibility($bucket, $key, $acl)
    {
        global $as3cf;

        $s3Client = $as3cf->get_s3client();

        $s3Client->putObjectAcl(array(
            'ACL' => $acl,
            'Bucket' => $bucket,
            'Key' => $key
        ));
    }
}
