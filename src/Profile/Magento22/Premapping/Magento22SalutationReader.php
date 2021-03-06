<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\MigrationMagento\Profile\Magento22\Premapping;

use Swag\MigrationMagento\Profile\Magento\DataSelection\CustomerAndOrderDataSelection;
use Swag\MigrationMagento\Profile\Magento\DataSelection\ProductReviewDataSelection;
use Swag\MigrationMagento\Profile\Magento2\Premapping\Magento2SalutationReader;
use Swag\MigrationMagento\Profile\Magento22\Magento22Profile;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class Magento22SalutationReader extends Magento2SalutationReader
{
    public function supports(MigrationContextInterface $migrationContext, array $entityGroupNames): bool
    {
        return $migrationContext->getProfile() instanceof Magento22Profile
            && (in_array(CustomerAndOrderDataSelection::IDENTIFIER, $entityGroupNames, true)
            || in_array(ProductReviewDataSelection::IDENTIFIER, $entityGroupNames, true));
    }
}
