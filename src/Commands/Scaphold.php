<?php

namespace Bonnier\WP\ContentHub\Editor\Commands;

use Bonnier\WP\ContentHub\Editor\Models\WpAttachment;
use Bonnier\WP\ContentHub\Editor\Models\WpComposite;
use Bonnier\WP\ContentHub\Editor\Scaphold\Client;
use WP_CLI;
use WP_CLI_Command;

/**
 * Class AdvancedCustomFields
 *
 * @package \Bonnier\WP\ContentHub\Commands
 */
class Scaphold extends WP_CLI_Command
{
    const CMD_NAMESPACE = 'scaphold';

    public static function register() {
        WP_CLI::add_command( CmdManager::CORE_CMD_NAMESPACE  . ' ' . static::CMD_NAMESPACE , __CLASS__ );
    }

    /**
     * Dumps ACF fields defined in code to a JSON importable file
     *
     * ## EXAMPLES
     *
     * wp contenthub editor scaphold fetch
     *
     */
    public function fetch() {

        $composites = collect(Client::query('  
            query AllComposites {
                viewer {
                allComposites {
                  edges {
                    cursor
                    node {
                      id
                    }
                  }
                }
              }
            }
        ')->allComposites->edges);

        //dd($composites);

        $composite = Client::query('  
            query GetComposite($id: ID!) {
              getComposite(id: $id) {
                id
                title
                description
                modifiedAt
                createdAt
                publishedAt
                kind
                canonicalUrl
                locale
                advertorialLabel
                status
                magazine
                translationSet {
                  id
                  masterLocale
                }
                recommended {
                  edges {
                    node {
                      id
                    }
                  }
                }
                teasers {
                  edges {
                    node {
                      title
                      description
                      image {
                        id
                        url
                        locale
                        trait
                        caption
                        altText
                      }
                      kind
                    }
                  }
                }
                source {
                  id
                  code
                  name
                }
                brand {
                  id
                  name
                  code
                }
                accessRules {
                  edges {
                    node {
                      id
                      kind
                      values
                      domain
                    }
                  }
                }
                content(first: 10000, orderBy: {field: position, direction: ASC}) {
                  edges {
                    node {
                      id
                      position
                      image {
                        id
                        url
                        locale
                        trait
                        caption
                        altText
                        copyright
                      }
                      textItem {
                        id
                        body
                        translationSet {
                          id
                        }
                      }
                      insertedCode {
                        id
                        code
                      }
                      associatedComposite {
                        id
                      }
                      infobox {
                        id
                        title
                        body
                        translationSet {
                          id
                        }
                      }
                      tag {
                        id
                        locale
                        name
                        translationSet {
                          id
                        }
                        vocabulary {
                          id
                          name
                        }
                      }
                      video {
                        id
                        thumbnailUrl
                        caption
                        service
                        locale
                        trait
                        videoIdentifier
                      }
                      file {
                        id
                        url
                        caption
                        accessRules {
                          edges {
                            node {
                              id
                              kind
                              values
                              domain
                            }
                          }
                        }
                      }
                      inventory {
                        id
                        title
                        items {
                          edges {
                            node {
                              id
                              position
                              name
                              values
                            }
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
        ', ['id' => 'Q29tcG9zaXRlOjI5' ])->getComposite;
        //', ['id' => 'Q29tcG9zaXRlOjEwNDc2Ng==' ])->getComposite;

        $existingId = WpComposite::id_from_contenthub_id($composite->id);

        $postId = wp_insert_post([
            'ID' => $existingId,
            'post_title' => $composite->title,
            'post_status' => collect([
                    'Published' => 'publish',
                    'Draft' => 'draft',
                    'Ready' => 'pending'
                ])
                ->get($composite->status, 'draft'),
            'post_type' => WpComposite::POST_TYPE,
            'post_date' => $composite->publishedAt ?? $composite->createdAt,
            'post_modified' => $composite->modifiedAt,
            'meta_input' => [
                WpComposite::POST_META_CONTENTHUB_ID => $composite->id
            ],
        ]);

        update_field('kind', $composite->kind, $postId);
        update_field('description', $composite->description, $postId);

        if($composite->magazine) {
            $magazineYearIssue = explode('-', $composite->magazine);
            update_field('magazine_year', $magazineYearIssue[0], $postId);
            update_field('magazine_issue', $magazineYearIssue[1], $postId);
        }

        update_field('commercial', !is_null($composite->advertorial_type), $postId);
        update_field('commercial_type', $composite->advertorial_type, $postId);


        $compositeContents = collect($composite->content->edges)->pluck('node')->map(function($compositeContent){
            return collect($compositeContent)->reject(function($property){
                return is_null($property);
            });
        })->map(function($compositeContent){
            $contentType = $compositeContent->except(['id', 'position'])->keys()->first();
            return (object) $compositeContent->only(['id', 'position'])->merge([
                'type' => snake_case($contentType),
                'content' => $compositeContent->get($contentType)
            ])->toArray();
        });


        //dd($compositeContents);

        $content = $compositeContents->map(function($compositeContent) use($postId){
            if($compositeContent->type === 'text_item') {
                return [
                    'body' => $compositeContent->content->body,
                    'acf_fc_layout' => $compositeContent->type
                ];
            }
            if($compositeContent->type === 'image') {
                return [
                    'lead_image' => $compositeContent->content->trait === 'Primary' ? true : false,
                    'file' => WpAttachment::upload_attachment($postId, $compositeContent->content),
                    'acf_fc_layout' => $compositeContent->type
                ];
            }
            if($compositeContent->type === 'video') {
                return [
                    'video' => $compositeContent->content->trait === 'Primary' ? true : false,
                    'file' => WpAttachment::upload_attachment($postId, $compositeContent->content),
                    'acf_fc_layout' => $compositeContent->type
                ];
            }
        });

        update_field('composite_content', $content->toArray(), $postId);




        //die(var_dump());

        //WP_CLI::success( "Successfully Dumped JSON to: " . $file );
        //WP_CLI::ERROR("Failed dumping file, please check that " . WP_CONTENT_DIR . " is write able");
    }


}