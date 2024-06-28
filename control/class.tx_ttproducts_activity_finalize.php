<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2016 Franz Holzinger (franz@ttproducts.de)
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*  A copy is found in the textfile GPL.txt and important notices to the license
*  from the author is found in LICENSE.txt distributed with these scripts.
*
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * Part of the tt_products (Shop System) extension.
 *
 * base class for the finalization activity
 *
 * @author  Franz Holzinger <franz@ttproducts.de>
 *
 * @maintainer	Franz Holzinger <franz@ttproducts.de>
 *
 * @package TYPO3
 * @subpackage tt_products
 */
use TYPO3\CMS\Core\Service\MarkerBasedTemplateService;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use JambageCom\Div2007\Utility\CompatibilityUtility;
use JambageCom\Div2007\Utility\MailUtility;

use JambageCom\TtProducts\Api\BasketApi;
use JambageCom\TtProducts\Api\CustomerApi;
use JambageCom\TtProducts\Api\Localization;


class tx_ttproducts_activity_finalize implements SingletonInterface
{
    public function getEmailControlArray(
        $templateCode,
        $conf,
        $fromArray
    ) {
        $suffixArray = [];
        if (is_array($conf['orderEmail.'])) {
            foreach ($conf['orderEmail.'] as $k => $emailConfig) {
                $suffix = strtolower($emailConfig['suffix']);
                $suffixArray[] = $suffix;
            }
        }

        if (in_array('customer', $suffixArray)) {
            $emailControlArray = [];
            $emailControlArray['customer']['none']['template'] = 'EMAIL_PLAINTEXT_TEMPLATE'; // keep this on first position of the array
            $emailControlArray['customer']['none']['recipient'] = [];
            if ($fromArray['customer']['email']) {
                $emailControlArray['customer']['none']['recipient'][] = $fromArray['customer']['email'];
            }

            $templateSubpart = 'EMAIL_HTML_TEMPLATE';
            if (strpos($templateCode, '###' . $templateSubpart . '###') === false) {
                $templateSubpart = 'BASKET_ORDERCONFIRMATION_TEMPLATE';
            }

            $emailControlArray['customer']['none']['htmltemplate'] = $templateSubpart;
            $emailControlArray['customer']['none']['from'] = $fromArray['customer'];
        }

        if (in_array('shop', $suffixArray)) {
            $emailControlArray['shop']['none']['from'] = $fromArray['shop'];

            if (!empty($conf['orderEmail_to'])) {
                $emailControlArray['shop']['none']['recipient'][] = $conf['orderEmail_to'];
            }
        }

        return $emailControlArray;
    }

    public function doProcessing(
        $templateCode,
        $mainMarkerArray,
        array $subpartArray,
        array $wrappedSubpartArray,
        $orderUid,
        &$orderArray,
        $productRowArray,
        $bAlwaysInStock,
        $useArticles,
        $addressArray,
        $bFinalVerify,
        $basketExt,
        $usedCreditpoints,
        &$errorCode, // neu
        &$errorMessage
    ) {
        $templateService = GeneralUtility::makeInstance(MarkerBasedTemplateService::class);
        $cnfObj = GeneralUtility::makeInstance('tx_ttproducts_config');
        $cnfObj->setConf('domain', '###LICENCE_DOMAIN###');
        $conf = $cnfObj->getConf();
        $basketObj = GeneralUtility::makeInstance('tx_ttproducts_basket');
        $markerObj = GeneralUtility::makeInstance('tx_ttproducts_marker');
        $tablesObj = GeneralUtility::makeInstance('tx_ttproducts_tables');
        $languageObj = GeneralUtility::makeInstance(Localization::class);
        $orderObj = $tablesObj->get('sys_products_orders');
        $basketApi = GeneralUtility::makeInstance(BasketApi::class);

        $basketView = GeneralUtility::makeInstance('tx_ttproducts_basket_view');
        $infoObj = GeneralUtility::makeInstance('tx_ttproducts_info');
        $infoViewObj = GeneralUtility::makeInstance('tx_ttproducts_info_view');
        $funcTablename = tx_ttproducts_control_basket::getFuncTablename();

        $customerEmail =
            $infoObj->getCustomerEmail();
        $useLoginEmail =
            CustomerApi::isSystemLoginUser(
                $conf
            );
        $defaultFromArray =
            $infoObj->getFromArray(
                $customerEmail,
                $useLoginEmail
            );

        $activityConf = $cnfObj->getBasketConf('activity', 'finalize');

        $basketExtra = $basketApi->getBasketExtra();
        $basketRecs = tx_ttproducts_control_basket::getRecs();


        $empty = '';

        $orderConfirmationHTML =
            $basketView->getView(
                $errorCode,
                $templateCode,
                'FINALIZE',
                $infoViewObj,
                false,
                false,
                $basketObj->getCalculatedArray(),
                true,
                'BASKET_ORDERCONFIRMATION_TEMPLATE',
                $mainMarkerArray,
                $subpartArray,
                $wrappedSubpartArray,
                '',
                $basketObj->getItemArray(),
                $notOverwritePriceIfSet = false,
                ['0' => $orderArray],
                [],
                $basketExtra
            );

        $markerArray = array_merge($mainMarkerArray, $markerObj->getGlobalMarkerArray());
        $markerArray['###CUSTOMER_RECIPIENTS_EMAIL###'] = $infoObj->getCustomerEmail();
        $orderConfirmationHTML = $templateService->substituteMarkerArray(
            $orderConfirmationHTML,
            $markerArray
        );
        $result = $orderConfirmationHTML;

        if (!$bAlwaysInStock) {
            $emailControlArray = $this->getEmailControlArray($templateCode, $conf, $defaultFromArray);

            debug ($funcTablename, '$funcTablename');
            $itemObj = $tablesObj->get($funcTablename);
            $instockTableArray =
                $itemObj->reduceInStockItems(
                    $basketObj->getItemArray(),
                    $useArticles
                );

            if (is_array($instockTableArray) && $conf['warningInStockLimit']) {
                $tableDescArray =
                    [
                        'tt_products' => 'product',
                        'tt_products_articles' => 'article',
                    ];
                foreach ($instockTableArray as $tablename => $instockArray) {
                    $tableDesc = $languageObj->getLabel($tableDescArray[$tablename]);

                    if (isset($instockArray) && is_array($instockArray)) {
                        foreach ($instockArray as $instockTmp => $count) {
                            $uidItemnrTitle = GeneralUtility::trimExplode(',', $instockTmp);

                            if ($count <= $conf['warningInStockLimit']) {
                                $content =
                                    sprintf(
                                        $languageObj->getLabel('instock_warning'),
                                        $tableDesc,
                                        $uidItemnrTitle[2],
                                        $uidItemnrTitle[1],
                                        intval($count)
                                    );

                                $subject =
                                    sprintf(
                                        $languageObj->getLabel('instock_warning_header'),
                                        $uidItemnrTitle[2],
                                        intval($count)
                                    );

                                if (
                                    isset($emailControlArray['shop']['none']['recipient']) && is_array($emailControlArray['shop']['none']['recipient'])
                                ) {
                                    foreach ($emailControlArray['shop']['none']['recipient'] as $key => $recipient) {
                                        $tmp = '';
                                        MailUtility::send(
                                            $recipient,
                                            $subject,
                                            $content,
                                            $tmp,	// no HTML order confirmation email for shop admins
                                            $emailControlArray['shop']['none']['from']['email'],
                                            $emailControlArray['shop']['none']['from']['name'],
                                            '',
                                            $emailControlArray['shop']['none']['cc'],
                                            $emailControlArray['shop']['none']['bcc'],
                                            $emailControlArray['shop']['none']['returnPath'],
                                            $emailControlArray['shop']['none']['replyTo'],
                                            TT_PRODUCTS_EXT,
                                            'sendMail'
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (
            !empty($infoViewObj->infoArray['billing']['email']) &&
            !CompatibilityUtility::isLoggedIn() &&
            (
                empty($GLOBALS['TSFE']->fe_user->user) ||
                empty($GLOBALS['TSFE']->fe_user->user['username'])
            )
        ) {
            // Move the user creation in front so that when we create the order we have a fe_userid so that the order lists work.

            $feuserUid = tx_ttproducts_api::createFeuser(
                $conf['createUsers'], // Is no user is logged in --> create one
                $templateCode,
                $conf,
                $infoViewObj,
                $basketView,
                $basketObj->getCalculatedArray(),
                $defaultFromArray
            );

            if ($feuserUid) {
                $infoViewObj->infoArray['billing']['feusers_uid'] = $feuserUid;
            }
        }

        if (isset($activityConf) && is_array($activityConf)) {
            if (isset($activityConf['clear'])) {
                $clearArray = GeneralUtility::trimExplode(',', $activityConf['clear']);
                foreach ($clearArray as $v) {
                    switch ($v) {
                        case 'memo':
                            $feuserField = 'tt_products_memoItems';
                            $memoItems = '';

                            if (
                                CompatibilityUtility::isLoggedIn() &&
                                !empty($GLOBALS['TSFE']->fe_user->user[$feuserField])
                            ) {
                                $memoItems = $GLOBALS['TSFE']->fe_user->user[$feuserField];
                            }
                            $uidArray = $basketObj->getUidArray();
                            if (
                                isset($uidArray) &&
                                is_array($uidArray) &&
                                count($uidArray) &&
                                $memoItems != ''
                            ) {
                                $newMemoItems = $memoItems;
                                foreach ($uidArray as $uid) {
                                    $newMemoItems = GeneralUtility::rmFromList($uid, $newMemoItems);
                                }

                                if ($newMemoItems != $memoItems) {
                                    tx_ttproducts_control_memo::saveMemo(
                                        'tt_products',
                                        $newMemoItems,
                                        $conf
                                    );
                                }
                            }
                            break;
                    }
                }
            }
        }

        if (!$bFinalVerify) {
            tx_ttproducts_api::finalizeOrder(
                $this,
                $templateCode,
                $mainMarkerArray,
                $funcTablename,
                $orderUid,
                $orderArray,
                $basketObj->getItemArray(),
                $basketObj->getCalculatedArray(),
                $addressArray,
                $basketExtra,
                $basketRecs,
                $basketExt['gift'] ?? '',
                $usedCreditpoints,
                $conf['debug'] ?? '',
                $errorMessage
            );
        }

        $orderObj->clearUid();

        return $result;
    } // doProcessing
}
