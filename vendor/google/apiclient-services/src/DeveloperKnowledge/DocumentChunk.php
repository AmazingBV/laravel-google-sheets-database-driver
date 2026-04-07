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

namespace Google\Service\DeveloperKnowledge;

class DocumentChunk extends \Google\Model
{
  /**
   * Output only. The content of the document chunk.
   *
   * @var string
   */
  public $content;
  /**
   * Output only. The ID of this chunk within the document. The chunk ID is
   * unique within a document, but not globally unique across documents. The
   * chunk ID is not stable and may change over time.
   *
   * @var string
   */
  public $id;
  /**
   * Output only. The resource name of the document this chunk is from. Format:
   * `documents/{uri_without_scheme}` Example:
   * `documents/docs.cloud.google.com/storage/docs/creating-buckets`
   *
   * @var string
   */
  public $parent;

  /**
   * Output only. The content of the document chunk.
   *
   * @param string $content
   */
  public function setContent($content)
  {
    $this->content = $content;
  }
  /**
   * @return string
   */
  public function getContent()
  {
    return $this->content;
  }
  /**
   * Output only. The ID of this chunk within the document. The chunk ID is
   * unique within a document, but not globally unique across documents. The
   * chunk ID is not stable and may change over time.
   *
   * @param string $id
   */
  public function setId($id)
  {
    $this->id = $id;
  }
  /**
   * @return string
   */
  public function getId()
  {
    return $this->id;
  }
  /**
   * Output only. The resource name of the document this chunk is from. Format:
   * `documents/{uri_without_scheme}` Example:
   * `documents/docs.cloud.google.com/storage/docs/creating-buckets`
   *
   * @param string $parent
   */
  public function setParent($parent)
  {
    $this->parent = $parent;
  }
  /**
   * @return string
   */
  public function getParent()
  {
    return $this->parent;
  }
}

// Adding a class alias for backwards compatibility with the previous class name.
class_alias(DocumentChunk::class, 'Google_Service_DeveloperKnowledge_DocumentChunk');
