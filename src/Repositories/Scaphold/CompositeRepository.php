<?php

namespace Bonnier\WP\ContentHub\Editor\Repositories\Scaphold;

use Bonnier\WP\ContentHub\Editor\Repositories\Contracts\Scaphold\CompositeContract;
use Bonnier\WP\ContentHub\Editor\Scaphold\Client;
use Bonnier\WP\ContentHub\Editor\Scaphold\Queries;
use GuzzleHttp\Exception\ClientException;

/**
 * Class CompositeRepository
 *
 * @package \Bonnier\WP\ContentHub\Repositories\Scaphold
 */
class CompositeRepository implements CompositeContract
{

    public static function get_all($cursor = '', $limit = 100)
    {
        return Client::query(Queries::GET_COMPOSITES, [
            'cursor' => $cursor,
            'limit' => $limit
        ])->allComposites;
    }

    public static function find_by_id($id)
    {
        return Client::query(Queries::GET_COMPOSITE, ['id' => $id])->getComposite ?? null;
    }

    public static function find_by_brand_id($id, $cursor = '', $limit = 100)
    {
        return Client::query(Queries::GET_COMPOSITES_BY_BRAND, [
            'brandId' => $id,
            'cursor' => $cursor,
            'limit' => $limit
        ])->allComposites;
    }

    public static function find_by_brand_id_and_source($id, $source, $cursor = '', $limit = 100)
    {
        return Client::query(Queries::GET_COMPOSITES_BY_BRAND_AND_SOURCE, [
            'source' => $source,
            'brandId' => $id,
            'cursor' => $cursor,
            'limit' => $limit
        ])->allComposites;
    }

    public static function create($input)
    {
        return Client::query(Queries::CREATE_COMPOSITE, [
            'input' => $input
        ])->createComposite->changedEdge->node->id ?? null;
    }

    public static function update($input)
    {
        return Client::query(Queries::UPDATE_COMPOSITE, [
                'input' => $input
        ])->updateComposite->changedEdge->node->id ?? null;
    }
}
