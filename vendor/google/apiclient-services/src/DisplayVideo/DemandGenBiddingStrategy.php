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

namespace Google\Service\DisplayVideo;

class DemandGenBiddingStrategy extends \Google\Model
{
  /**
   * Bidding source is not specified or unknown.
   */
  public const EFFECTIVE_BIDDING_VALUE_SOURCE_BIDDING_SOURCE_UNSPECIFIED = 'BIDDING_SOURCE_UNSPECIFIED';
  /**
   * Bidding value is inherited from the line item.
   */
  public const EFFECTIVE_BIDDING_VALUE_SOURCE_BIDDING_SOURCE_LINE_ITEM = 'BIDDING_SOURCE_LINE_ITEM';
  /**
   * Bidding value is defined in the ad group.
   */
  public const EFFECTIVE_BIDDING_VALUE_SOURCE_BIDDING_SOURCE_AD_GROUP = 'BIDDING_SOURCE_AD_GROUP';
  /**
   * Type is not specified or unknown.
   */
  public const TYPE_DEMAND_GEN_BIDDING_STRATEGY_TYPE_UNSPECIFIED = 'DEMAND_GEN_BIDDING_STRATEGY_TYPE_UNSPECIFIED';
  /**
   * A bidding strategy that automatically optimizes conversions per dollar.
   */
  public const TYPE_DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_CPA = 'DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_CPA';
  /**
   * A bidding strategy that automatically maximizes revenue while averaging a
   * specific target Return On Ad Spend (ROAS).
   */
  public const TYPE_DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_ROAS = 'DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_ROAS';
  /**
   * A bidding strategy that automatically maximizes number of conversions
   */
  public const TYPE_DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CONVERSIONS = 'DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CONVERSIONS';
  /**
   * A bidding strategy that automatically maximizes revenue while spending your
   * budget.
   */
  public const TYPE_DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CONVERSION_VALUE = 'DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CONVERSION_VALUE';
  /**
   * A bidding strategy that automatically maximizes clicks within a given
   * budget.
   */
  public const TYPE_DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CLICKS = 'DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CLICKS';
  /**
   * Output only. If AG doesn't set value for tCPA or tROAS, line item bidding
   * value will be the effective_bidding_value, if the bidding strategy type is
   * not tCPA or tROAS, effective_bidding_value is always 0. For line item, it
   * will be the same as the value field.
   *
   * @var string
   */
  public $effectiveBiddingValue;
  /**
   * Output only. Source of the effective bidding value.
   *
   * @var string
   */
  public $effectiveBiddingValueSource;
  /**
   * Optional. The type of the bidding strategy. This can only be set at the
   * line item level.
   *
   * @var string
   */
  public $type;
  /**
   * Optional. The value used by the bidding strategy. This can be set at the
   * line item and ad group level. This field is only applicable for the
   * following strategy types: * `DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_CPA` *
   * `DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_CPC` *
   * `DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_ROAS` Value of this field is in
   * micros of the advertiser's currency or ROAS value. For example, 1000000
   * represents 1.0 standard units of the currency or 100% ROAS value. If not
   * using an applicable strategy, the value of this field will be 0.
   *
   * @var string
   */
  public $value;

  /**
   * Output only. If AG doesn't set value for tCPA or tROAS, line item bidding
   * value will be the effective_bidding_value, if the bidding strategy type is
   * not tCPA or tROAS, effective_bidding_value is always 0. For line item, it
   * will be the same as the value field.
   *
   * @param string $effectiveBiddingValue
   */
  public function setEffectiveBiddingValue($effectiveBiddingValue)
  {
    $this->effectiveBiddingValue = $effectiveBiddingValue;
  }
  /**
   * @return string
   */
  public function getEffectiveBiddingValue()
  {
    return $this->effectiveBiddingValue;
  }
  /**
   * Output only. Source of the effective bidding value.
   *
   * Accepted values: BIDDING_SOURCE_UNSPECIFIED, BIDDING_SOURCE_LINE_ITEM,
   * BIDDING_SOURCE_AD_GROUP
   *
   * @param self::EFFECTIVE_BIDDING_VALUE_SOURCE_* $effectiveBiddingValueSource
   */
  public function setEffectiveBiddingValueSource($effectiveBiddingValueSource)
  {
    $this->effectiveBiddingValueSource = $effectiveBiddingValueSource;
  }
  /**
   * @return self::EFFECTIVE_BIDDING_VALUE_SOURCE_*
   */
  public function getEffectiveBiddingValueSource()
  {
    return $this->effectiveBiddingValueSource;
  }
  /**
   * Optional. The type of the bidding strategy. This can only be set at the
   * line item level.
   *
   * Accepted values: DEMAND_GEN_BIDDING_STRATEGY_TYPE_UNSPECIFIED,
   * DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_CPA,
   * DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_ROAS,
   * DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CONVERSIONS,
   * DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CONVERSION_VALUE,
   * DEMAND_GEN_BIDDING_STRATEGY_TYPE_MAXIMIZE_CLICKS
   *
   * @param self::TYPE_* $type
   */
  public function setType($type)
  {
    $this->type = $type;
  }
  /**
   * @return self::TYPE_*
   */
  public function getType()
  {
    return $this->type;
  }
  /**
   * Optional. The value used by the bidding strategy. This can be set at the
   * line item and ad group level. This field is only applicable for the
   * following strategy types: * `DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_CPA` *
   * `DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_CPC` *
   * `DEMAND_GEN_BIDDING_STRATEGY_TYPE_TARGET_ROAS` Value of this field is in
   * micros of the advertiser's currency or ROAS value. For example, 1000000
   * represents 1.0 standard units of the currency or 100% ROAS value. If not
   * using an applicable strategy, the value of this field will be 0.
   *
   * @param string $value
   */
  public function setValue($value)
  {
    $this->value = $value;
  }
  /**
   * @return string
   */
  public function getValue()
  {
    return $this->value;
  }
}

// Adding a class alias for backwards compatibility with the previous class name.
class_alias(DemandGenBiddingStrategy::class, 'Google_Service_DisplayVideo_DemandGenBiddingStrategy');
