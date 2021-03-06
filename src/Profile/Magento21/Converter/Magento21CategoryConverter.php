<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\MigrationMagento\Profile\Magento21\Converter;

use Swag\MigrationMagento\Profile\Magento\DataSelection\DataSet\CategoryDataSet;
use Swag\MigrationMagento\Profile\Magento2\Converter\Magento2CategoryConverter;
use Swag\MigrationMagento\Profile\Magento21\Magento21Profile;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class Magento21CategoryConverter extends Magento2CategoryConverter
{
    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile()->getName() === Magento21Profile::PROFILE_NAME
            && $migrationContext->getDataSet()::getEntity() === CategoryDataSet::getEntity();
    }
}
