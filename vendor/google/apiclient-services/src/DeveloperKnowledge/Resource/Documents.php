<?php
/*
 * Copyright 2014 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not
 * use this file except in compliance with the License. You may obtain a copy of
 * the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations under
 * the License.
 */

namespace Google\Service\DeveloperKnowledge\Resource;

use Google\Service\DeveloperKnowledge\BatchGetDocumentsResponse;
use Google\Service\DeveloperKnowledge\Document;
use Google\Service\DeveloperKnowledge\SearchDocumentChunksResponse;

/**
 * The "documents" collection of methods.
 * Typical usage is:
 *  <code>
 *   $developerknowledgeService = new Google\Service\DeveloperKnowledge(...);
 *   $documents = $developerknowledgeService->documents;
 *  </code>
 */
class Documents extends \Google\Service\Resource
{
  /**
   * Retrieves multiple documents, each with its full Markdown content.
   * (documents.batchGet)
   *
   * @param array $optParams Optional parameters.
   *
   * @opt_param string names Required. The names of the documents to retrieve. A
   * maximum of 20 documents can be retrieved in a batch. The documents are
   * returned in the same order as the `names` in the request. Format:
   * `documents/{uri_without_scheme}` Example:
   * `documents/docs.cloud.google.com/storage/docs/creating-buckets`
   * @return BatchGetDocumentsResponse
   * @throws \Google\Service\Exception
   */
  public function batchGet($optParams = [])
  {
    $params = [];
    $params = array_merge($params, $optParams);
    return $this->call('batchGet', [$params], BatchGetDocumentsResponse::class);
  }
  /**
   * Retrieves a single document with its full Markdown content. (documents.get)
   *
   * @param string $name Required. The name of the document to retrieve. Format:
   * `documents/{uri_without_scheme}` Example:
   * `documents/docs.cloud.google.com/storage/docs/creating-buckets`
   * @param array $optParams Optional parameters.
   * @return Document
   * @throws \Google\Service\Exception
   */
  public function get($name, $optParams = [])
  {
    $params = ['name' => $name];
    $params = array_merge($params, $optParams);
    return $this->call('get', [$params], Document::class);
  }
  /**
   * Searches for developer knowledge across Google's developer documentation.
   * This method returns document chunks based on the user's query. There can be
   * many chunks of the same Document. To retrieve full documents, use
   * DeveloperKnowledge.GetDocument or DeveloperKnowledge.BatchGetDocuments with
   * the DocumentChunk.parent returned in the
   * SearchDocumentChunksResponse.results. (documents.searchDocumentChunks)
   *
   * @param array $optParams Optional parameters.
   *
   * @opt_param int pageSize Optional. The maximum number of results to return.
   * The service may return fewer than this value. If unspecified, at most 5
   * results will be returned. The maximum value is 20; values above 20 will
   * result in an INVALID_ARGUMENT error.
   * @opt_param string pageToken Optional. A page token, received from a previous
   * `SearchDocumentChunks` call. Provide this to retrieve the subsequent page.
   * @opt_param string query Required. The raw query string provided by the user,
   * such as "How to create a Cloud Storage bucket?".
   * @return SearchDocumentChunksResponse
   * @throws \Google\Service\Exception
   */
  public function searchDocumentChunks($optParams = [])
  {
    $params = [];
    $params = array_merge($params, $optParams);
    return $this->call('searchDocumentChunks', [$params], SearchDocumentChunksResponse::class);
  }
}

// Adding a class alias for backwards compatibility with the previous class name.
class_alias(Documents::class, 'Google_Service_DeveloperKnowledge_Resource_Documents');
