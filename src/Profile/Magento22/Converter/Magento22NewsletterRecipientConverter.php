<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\MigrationMagento\Profile\Magento22\Converter;

use Swag\MigrationMagento\Profile\Magento\DataSelection\DataSet\NewsletterRecipientDataSet;
use Swag\MigrationMagento\Profile\Magento2\Converter\Magento2NewsletterRecipientConverter;
use Swag\MigrationMagento\Profile\Magento22\Magento22Profile;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class Magento22NewsletterRecipientConverter extends Magento2NewsletterRecipientConverter
{
    public function supports(MigrationContextInterface $migrationContext): bool
    {
        return $migrationContext->getProfile()->getName() === Magento22Profile::PROFILE_NAME
             && $migrationContext->getDataSet()::getEntity() === NewsletterRecipientDataSet::getEntity();
    }
}
