<?php

namespace CloudCreativity\LaravelJsonApi\Http\Requests;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Model;
use CloudCreativity\LaravelJsonApi\Document\ResourceObject;
use CloudCreativity\LaravelJsonApi\Eloquent\AbstractAdapter;
use Neomerx\JsonApi\Contracts\Encoder\Parameters\EncodingParametersInterface;

/**
 * Trait CreateIncluded
 * @package CloudCreativity\LaravelJsonApi\Http\Requests
 */
trait CreateIncluded
{
    /**
     * Create all included resources
     *
     * @param array $document
     * @param ResourceObject $resource
     * @param EncodingParametersInterface $parameters
     * @param $record
     * @return ResourceObject
     */
    protected function createIncluded(
        array $document,
        ResourceObject $resource,
        EncodingParametersInterface $parameters,
        $record): ResourceObject
    {
        /**  Check included member is present: */
        $included = data_get($document, 'included');
        if (!$included) {
            return $resource;
        }

        /** Check all relationships for included resources: */
        foreach ($resource->getRelationships() as $relationName => $relationship) {
            $adapter = null;
            /** Create included resources for given relationship if needed: */
            foreach ($this->findIncludedData($relationship, $included) as $includedData) {
                /** @var AbstractAdapter $adapter */
                /** Prepare adapter for included resource by its type: */
                $adapter = $adapter ?: $this->getStore()->adapterFor($includedData['type']);
                /** Create included record instance: */
                $includedRecord = $adapter->createRecord(
                    $includedResource = $adapter->deserialize(['data' => $includedData])
                );
                /** Fill included record by included resource: */
                $adapter->fill($includedRecord, $includedResource, $parameters);
                /** Persist included record: */
                $id = $this->persistIncludedRecord($relationName, $record, $includedRecord);
                /** Update parent resource by persisted relation: */
                $resource = $this->updateResourceRelationship($relationName, $document, $includedData['lid'], $id);
            }
        }

        return $resource;
    }

    /**
     * Find included resources for given relation,
     * return empty array if not have
     *
     * @param array $relationship
     * @param array $included
     * @return array
     */
    protected function findIncludedData(array $relationship, array $included) : array
    {
        /** Get relationship data and check is one or many: */
        $data = $relationship['data'];
        if (data_get($data, 'type')) {
            $data = [$data];
        }

        /** Prepare result resources for given relationship: */
        $result = [];
        foreach ($data as $resourceData) {
            /** Skip if relationship not have lid: */
            if (!$includedLid = data_get($resourceData, 'lid')) {
                continue;
            }

            /** Search included resource by type and lid: */
            $includedType = data_get($resourceData, 'type');
            foreach ($included as $includedData) {
                if (data_get($includedData, 'type') === $includedType &&
                    data_get($includedData, 'lid') === $includedLid) {
                    $result[] = $includedData;
                    break;
                }
            }
        }

        /** Return matched included resources: */
        return $result;
    }

    /**
     * Persist included record
     *
     * @param string $relationName
     * @param Model $record
     * @param Model $includedRecord
     * @return string|null
     */
    protected function persistIncludedRecord(string $relationName, Model $record, Model $includedRecord): ?string
    {
        $includedRecord->save();
        return (string) $includedRecord->getId();
    }

    /**
     * Change relationship lid to real id or unset,
     * return new resource object
     *
     * @param array $document
     * @param string $relationName
     * @param $lid
     * @param null|mixed $id
     * @return ResourceObject
     */
    protected function updateResourceRelationship(string $relationName, array &$document, $lid, $id = null): ResourceObject
    {
        /** Pull relationship from document: */
        $key = "data.relationships.$relationName.data";
        $relationship = data_get($document, $key);
        Arr::forget($document, "data.relationships.$relationName");

        /** Prepare relationship for updating - convert to many: */
        $isMany = true;
        if (!empty($relationship['type'])) {
            $isMany = false;
            $relationship = [$relationship];
        }

        /** Update relations: */
        foreach ($relationship as $i => &$item) {
            if ($item['lid'] === $lid) {
                /** Change lid to id if present: */
                if ($id) {
                    $item['id'] = $id;
                    unset($item['lid']);
                    /** Remove relation if id is not present: */
                } else {
                    unset($relationship[$i]);
                }
                break;
            }
        }
        unset($item);

        /** Prepare relationship for setting - convert from many if needed: */
        if (!$isMany) {
            $relationship = reset($relationship);
        }

        /** Set relationship in document if not empty: */
        if (!empty($relationship)) {
            data_set($document, $key, $relationship);
        }

        /** Create and return new resource object: */
        return $this->deserialize($document);
    }
}
