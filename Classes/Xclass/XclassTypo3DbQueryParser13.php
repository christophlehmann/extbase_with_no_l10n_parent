<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace GeorgRinger\ExtbaseRecordsWithNoL10nParent\Xclass;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Storage\Typo3DbQueryParser;

/**
 * Xclass to add missing language statement
 */
#[Autoconfigure(public: true, shared: false)]
class XclassTypo3DbQueryParser13 extends Typo3DbQueryParser
{
    /**
     * Builds the language field statement
     *
     * @param string $tableName The database table name
     * @param string $tableAlias The table alias used in the query.
     * @param QuerySettingsInterface $querySettings The TYPO3 CMS specific query settings
     * @return CompositeExpression|string
     */
    protected function getLanguageStatement($tableName, $tableAlias, QuerySettingsInterface $querySettings)
    {
        if (empty($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
            return '';
        }

        if (!$this->handleTable($tableName)) {
            return parent::getLanguageStatement($tableName, $tableAlias, $querySettings);
        }

        // Select all entries for the current language
        // If any language is set -> get those entries which are not translated yet
        // They will be removed by \TYPO3\CMS\Core\Domain\Repository\PageRepository::getRecordOverlay if not matching overlay mode
        $languageField = $GLOBALS['TCA'][$tableName]['ctrl']['languageField'];

        $languageAspect = $querySettings->getLanguageAspect();

        $transOrigPointerField = $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] ?? '';
        if (!$transOrigPointerField || !$languageAspect->getContentId()) {
            return $this->queryBuilder->expr()->in(
                $tableAlias . '.' . $languageField,
                [$languageAspect->getContentId(), -1]
            );
        }

        if (!$languageAspect->doOverlays()) {
            return $this->queryBuilder->expr()->in(
                $tableAlias . '.' . $languageField,
                [$languageAspect->getContentId(), -1]
            );
        }

        $defLangTableAlias = $tableAlias . '_dl';
        $defaultLanguageRecordsSubSelect = $this->queryBuilder->getConnection()->createQueryBuilder();
        $defaultLanguageRecordsSubSelect
            ->select($defLangTableAlias . '.uid')
            ->from($tableName, $defLangTableAlias)
            ->where(
                $defaultLanguageRecordsSubSelect->expr()->and(
                    $defaultLanguageRecordsSubSelect->expr()->eq($defLangTableAlias . '.' . $transOrigPointerField, 0),
                    $defaultLanguageRecordsSubSelect->expr()->eq($defLangTableAlias . '.' . $languageField, 0)
                )
            );

        $andConditions = [];
        // records in language 'all'
        $andConditions[] = $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, -1);
        // translated records where a default language exists
        $andConditions[] = $this->queryBuilder->expr()->and(
            $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, $languageAspect->getContentId()),
            $this->queryBuilder->expr()->in(
                $tableAlias . '.' . $transOrigPointerField,
                $defaultLanguageRecordsSubSelect->getSQL()
            )
        );

        // XCLASS begin
        // records in translation with no default language
        $andConditions[] = $this->queryBuilder->expr()->and(
            $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, $languageAspect->getContentId()),
            $this->queryBuilder->expr()->eq($tableAlias . '.' . $transOrigPointerField, 0)
        );
        // XCLASS end

        // Records in translation with no default language
        if ($languageAspect->getOverlayType() === LanguageAspect::OVERLAYS_ON_WITH_FLOATING) {
            $andConditions[] = $this->queryBuilder->expr()->and(
                $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, $languageAspect->getContentId()),
                $this->queryBuilder->expr()->eq($tableAlias . '.' . $transOrigPointerField, 0),
                $this->queryBuilder->expr()->notIn(
                    $tableAlias . '.' . $transOrigPointerField,
                    $defaultLanguageRecordsSubSelect->getSQL()
                )
            );
        }
        if ($languageAspect->getOverlayType() === LanguageAspect::OVERLAYS_MIXED) {
            // returns records from current language which have a default language
            // together with not translated default language records
            $translatedOnlyTableAlias = $tableAlias . '_to';
            $queryBuilderForSubselect = $this->queryBuilder->getConnection()->createQueryBuilder();
            $queryBuilderForSubselect
                ->select($translatedOnlyTableAlias . '.' . $transOrigPointerField)
                ->from($tableName, $translatedOnlyTableAlias)
                ->where(
                    $queryBuilderForSubselect->expr()->and(
                        $queryBuilderForSubselect->expr()->gt($translatedOnlyTableAlias . '.' . $transOrigPointerField, 0),
                        $queryBuilderForSubselect->expr()->eq($translatedOnlyTableAlias . '.' . $languageField, $languageAspect->getContentId())
                    )
                );
            // records in default language, which do not have a translation
            $andConditions[] = $this->queryBuilder->expr()->and(
                $this->queryBuilder->expr()->eq($tableAlias . '.' . $languageField, 0),
                $this->queryBuilder->expr()->notIn(
                    $tableAlias . '.uid',
                    $queryBuilderForSubselect->getSQL()
                )
            );
        }

        return $this->queryBuilder->expr()->or(...$andConditions);
    }

    protected function handleTable(string $tableName): bool
    {
        try {
            $settings = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('extbase_with_no_l10n_parent');
            if (is_array($settings) && isset($settings['tables'])) {
                if ($settings['tables'] === '*') {
                    return true;
                }

                return GeneralUtility::inList($settings['tables'], $tableName);
            }
        } catch (ExtensionConfigurationExtensionNotConfiguredException) {
            return false;
        } catch (ExtensionConfigurationPathDoesNotExistException) {
            return false;
        }

        return false;
    }

}
