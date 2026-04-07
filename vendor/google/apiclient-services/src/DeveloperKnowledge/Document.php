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

class Document extends \Google\Model
{
  /**
   * Output only. The full content of the document in Markdown format.
   *
   * @var string
   */
  public $content;
  /**
   * Output only. A description of the document.
   *
   * @var string
   */
  public $description;
  /**
   * Identifier. The resource name of the document. Format:
   * `documents/{uri_without_scheme}` Example:
   * `documents/docs.cloud.google.com/storage/docs/creating-buckets`
   *
   * @var string
   */
  public $name;
  /**
   * Output only. The URI of the content, such as
   * `docs.cloud.google.com/storage/docs/creating-buckets`.
   *
   * @var string
   */
  public $uri;

  /**
   * Output only. The full content of the document in Markdown format.
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
   * Output only. A description of the document.
   *
   * @param string $description
   */
  public function setDescription($description)
  {
    $this->description = $description;
  }
  /**
   * @return string
   */
  public function getDescription()
  {
    return $this->description;
  }
  /**
   * Identifier. The resource name of the document. Format:
   * `documents/{uri_without_scheme}` Example:
   * `documents/docs.cloud.google.com/storage/docs/creating-buckets`
   *
   * @param string $name
   */
  public function setName($name)
  {
    $this->name = $name;
  }
  /**
   * @return string
   */
  public function getName()
  {
    return $this->name;
  }
  /**
   * Output only. The URI of the content, such as
   * `docs.cloud.google.com/storage/docs/creating-buckets`.
   *
   * @param string $uri
   */
  public function setUri($uri)
  {
    $this->uri = $uri;
  }
  /**
   * @return string
   */
  public function getUri()
  {
    return $this->uri;
  }
}

// Adding a class alias for backwards compatibility with the previous class name.
class_alias(Document::class, 'Google_Service_DeveloperKnowledge_Document');
