<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\MigrationMagento\Profile\Magento21\Converter;

use Swag\MigrationMagento\Profile\Magento\DataSelection\DataSet\ProductCustomFieldDataSet;
use Swag\MigrationMagento\Profile\Magento2\Converter\Magento2ProductCustomFieldConverter;
use Swag\MigrationMagento\Profile\Magento21\Magento21Profile;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class Magento21ProductCustomFieldConverter extends Magento2ProductCustomFieldConverter
{
    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile()->getName() === Magento21Profile::PROFILE_NAME
            && $migrationContext->getDataSet()::getEntity() === ProductCustomFieldDataSet::getEntity();
    }

    protected function getCustomFieldEntityName(): string
    {
        return DefaultEntities::PRODUCT;
    }
}
