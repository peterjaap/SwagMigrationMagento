<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\MigrationMagento\Profile\Magento20\Premapping;

use Swag\MigrationMagento\Profile\Magento\DataSelection\ProductDataSelection;
use Swag\MigrationMagento\Profile\Magento\DataSelection\ProductReviewDataSelection;
use Swag\MigrationMagento\Profile\Magento\DataSelection\SeoUrlDataSelection;
use Swag\MigrationMagento\Profile\Magento2\Premapping\Magento2TaxReader;
use Swag\MigrationMagento\Profile\Magento20\Magento20Profile;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class Magento20TaxReader extends Magento2TaxReader
{
    public function supports(MigrationContextInterface $migrationContext, array $entityGroupNames): bool
    {
        return $migrationContext->getProfile() instanceof Magento20Profile
            && (in_array(ProductDataSelection::IDENTIFIER, $entityGroupNames, true)
            || in_array(ProductReviewDataSelection::IDENTIFIER, $entityGroupNames, true)
            || in_array(SeoUrlDataSelection::IDENTIFIER, $entityGroupNames, true));
    }
}
