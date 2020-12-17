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

namespace CloudCreativity\LaravelJsonApi\Http\Requests;

use CloudCreativity\LaravelJsonApi\Contracts\Validation\ValidatorFactoryInterface;

/**
 * Class CreateResource
 *
 * @package CloudCreativity\LaravelJsonApi
 */
class CreateResource extends ValidatedRequest
{

    /**
     * @inheritDoc
     */
    protected function authorize()
    {
        if (!$authorizer = $this->getAuthorizer()) {
            return;
        }

        $authorizer->create($this->getType(), $this->request);
    }

    /**
     * @inheritDoc
     */
    protected function validateQuery()
    {
        if ($validators = $this->getValidators()) {
            $this->passes(
                $validators->modifyQuery($this->query())
            );
        }
    }

    /**
     * @inheritDoc
     */
    protected function validateDocument()
    {
        $document = $this->decode();
        $validators = $this->getValidators();

        /** If there is a decoded JSON API document, check it complies with the spec. */
        if ($document) {
            $this->validateDocumentCompliance($document, $validators);
            /** Validate included resources by spec v1.1 */
            $this->validateDocumentIncluded($document);
        }

        /** Check the document is logically correct. */
        if ($validators) {
            $this->passes($validators->create($this->all()));
        }
    }

    /**
     * Validate the JSON API document complies with the spec.
     *
     * @param object $document
     * @param ValidatorFactoryInterface|null $validators
     */
    protected function validateDocumentCompliance($document, ?ValidatorFactoryInterface $validators): void
    {
        $this->passes(
            $this->factory->createNewResourceDocumentValidator(
                $document,
                $this->getResourceType(),
                $validators && $validators->supportsClientIds()
            )
        );
    }

    /**
     * Validate document included resources
     *
     * @param $document
     */
    protected function validateDocumentIncluded($document): void
    {
        if (property_exists($document, 'included')) {
            foreach ($document->included as $item) {
                $includedValidators = $this->getValidators($item->type);
                if ($includedValidators) {
                    $this->passes($includedValidators->create($this->getIncludedDocument($item)));
                }
            }
        }
    }

    /**
     * Transform object to array
     *
     * @param object $item
     * @return array
     */
    protected function getIncludedDocument(object $item): array
    {
        try {
            return [
                'data' => json_decode(
                    json_encode($item, JSON_THROW_ON_ERROR),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            ];
        } catch (\JsonException $e) {
            return [];
        }
    }
}
