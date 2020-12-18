<?php
/**
 * Copyright 2020 Cloud Creativity Limited
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CloudCreativity\LaravelJsonApi\Validation\Spec;

use CloudCreativity\LaravelJsonApi\Contracts\Store\StoreInterface;
use CloudCreativity\LaravelJsonApi\Document\Error\Translator as ErrorTranslator;
use CloudCreativity\LaravelJsonApi\Exceptions\InvalidArgumentException;

/**
 * Class CreateResourceValidator
 *
 * @package CloudCreativity\LaravelJsonApi
 */
class CreateResourceValidator extends AbstractValidator
{

    /**
     * The expected JSON API type.
     *
     * @var string
     */
    private $expectedType;

    /**
     * Whether client ids are supported.
     *
     * @var bool
     */
    private $clientIds;

    /**
     * CreateResourceValidator constructor.
     *
     * @param StoreInterface $store
     * @param ErrorTranslator $translator
     * @param object $document
     * @param string $expectedType
     * @param bool $clientIds
     *      whether client ids are supported.
     */
    public function __construct(
        StoreInterface $store,
        ErrorTranslator $translator,
        $document,
        string $expectedType,
        bool $clientIds = false
    ) {
        if (empty($expectedType)) {
            throw new InvalidArgumentException('Expecting type to be a non-empty string.');
        }

        parent::__construct($store, $translator, $document);
        $this->expectedType = $expectedType;
        $this->clientIds = $clientIds;
    }

    /**
     * @inheritDoc
     */
    protected function validate(): bool
    {
        /** If the data is not valid, we cannot validate the resource. */
        if (!$this->validateData()) {
            return false;
        }

        return $this->validateResource();
    }

    /**
     * Validate that the top-level `data` member is acceptable.
     *
     * @return bool
     */
    protected function validateData(): bool
    {
        if (!property_exists($this->document, 'data')) {
            $this->memberRequired('/', 'data');
            return false;
        }

        $data = $this->document->data;

        if (!is_object($data)) {
            $this->memberNotObject('/', 'data');
            return false;
        }

        return true;
    }

    /**
     * Validate the resource object.
     * (with spec 1.1)
     *
     * @return bool
     */
    protected function validateResource(): bool
    {
        $identifier = $this->validateTypeAndId();
        $attributes = $this->validateAttributes();
        $relationships = $this->validateRelationships();
        /** validate all included resources if present: */
        $included = $this->validateIncluded();

        if ($attributes && $relationships) {
            return $this->validateAllFields() && $identifier && $included;
        }

        return $identifier && $attributes && $relationships && $included;
    }

    /**
     * Validate the resource type and id.
     *
     * @return bool
     */
    protected function validateTypeAndId(): bool
    {
        if (!($this->validateType() && $this->validateId())) {
            return false;
        }

        $type = $this->dataGet('type');
        $id = $this->dataGet('id');

        if ($id && !$this->isNotFound($type, $id)) {
            $this->resourceExists($type, $id);
            return false;
        }

        return true;
    }

    /**
     * Validate the resource type.
     *
     * @return bool
     */
    protected function validateType(): bool
    {
        if (!$this->dataHas('type')) {
            $this->memberRequired('/data', 'type');
            return false;
        }

        $value = $this->dataGet('type');

        if (!$this->validateTypeMember($value, '/data')) {
            return false;
        }

        if ($this->expectedType !== $value) {
            $this->resourceTypeNotSupported($value);
            return false;
        }

        return true;
    }

    /**
     * Validate the resource id.
     *
     * @return bool
     */
    protected function validateId(): bool
    {
        if (!$this->dataHas('id')) {
            return true;
        }

        $valid = $this->validateIdMember($this->dataGet('id'), '/data');

        if (!$this->supportsClientIds()) {
            $valid = false;
            $this->resourceDoesNotSupportClientIds($this->expectedType);
        }

        return $valid;
    }

    /**
     * Validate the resource attributes.
     * (with spec 1.1)
     *
     * @param null $attrs
     * @param string $path
     *          path to resource attributes in document
     * @return bool
     */
    protected function validateAttributes($attrs = null, string $path = '/data'): bool
    {
        // get and check attributes if not present:
        if ($attrs === null) {
            $key = $this->keyFromPath($path);
            $attrs = data_get($this->document, "$key.attributes");
            if ($attrs === null) {
                return true;
            }
        }

        if (!is_object($attrs)) {
            $this->memberNotObject($path, 'attributes');
            return false;
        }

        $disallowed = collect(['type', 'id'])->filter(function ($field) use ($attrs) {
            return property_exists($attrs, $field);
        });

        $this->memberFieldsNotAllowed($path, 'attributes', $disallowed);

        return $disallowed->isEmpty();
    }

    /**
     * Validate included resources
     * (for spec 1.1)
     *
     * @return bool
     */
    protected function validateIncluded(): bool
    {
        /** Check included member */
        if (!property_exists($this->document, 'included')) {
            return true;
        }
        if (empty($this->document->included)) {
            $this->invalidResource('/included', 'Empty included not allowed!');
            return false;
        }
        if (!is_array($this->document->included)) {
            $this->invalidResource('/included', 'Included must be an array!');
            return false;
        }

        $valid = true;
        /** Validate all included resources */
        foreach ($this->document->included as $index => $item) {
            $required = $this->validateRequiredMembers($item, "/included/$index");
            $relationships = $this->validateRelationships("/included/$index");
            if (!$required || !$relationships) {
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Validate the included resource required members
     * (for spec 1.1)
     *
     * @param object $item
     * @param string $path
     * @return bool
     */
    protected function validateRequiredMembers(object $item, string $path): bool
    {
        $valid = true;

        /** Required members and validate methods map: */
        $validate = [
            'type' => 'validateTypeMember',
            'lid' => 'validateLidMember',
            'attributes' => 'validateAttributes'
        ];

        foreach ($validate as $field => $method) {
            /** Check required member exists: */
            if (!property_exists($item, $field)) {
                $this->memberRequired($path, $field);
                $valid = false;
                continue;
            }
            /** Validate required member value: */
            $value = data_get($item, $field);
            if (!$this->$method($value, $path)) {
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Validate the resource relationships.
     *
     * @param string $path - path to relationships member in document
     * @return bool
     */
    protected function validateRelationships(string $path = '/data'): bool
    {
        // get relationships from document:
        $key = $this->keyFromPath($path);
        $relationships = data_get($this->document, "$key.relationships");
        if ($relationships === null) {
            return true;
        }

        if (!is_object($relationships)) {
            $this->memberNotObject($path, 'relationships');
            return false;
        }

        $disallowed = collect(['type', 'id'])->filter(function ($field) use ($relationships) {
            return property_exists($relationships, $field);
        });

        $valid = $disallowed->isEmpty();
        $this->memberFieldsNotAllowed($path, 'relationships', $disallowed);

        foreach ($relationships as $field => $relation) {
            if (!$this->validateRelationship($relation, $field, $path)) {
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Validate the resource's attributes and relationships collectively.
     *
     * @return bool
     */
    protected function validateAllFields(): bool
    {
        $duplicates = collect(
            (array) $this->dataGet('attributes', [])
        )->intersectByKeys(
            (array) $this->dataGet('relationships', [])
        )->keys();

        $this->resourceFieldsExistInAttributesAndRelationships($duplicates);

        return $duplicates->isEmpty();
    }

    /**
     * Are client ids supported?
     *
     * @return bool
     */
    protected function supportsClientIds(): bool
    {
        return $this->clientIds;
    }

    /**
     * Transform member path in document key
     *
     * @param string $path
     * @return string
     */
    protected function keyFromPath(string $path): string
    {
        return str_replace('/', '.', trim($path, '/'));
    }
}
