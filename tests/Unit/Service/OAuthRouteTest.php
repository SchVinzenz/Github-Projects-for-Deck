<?php

declare(strict_types=1);

namespace OCA\DeckGithubSync\Tests\Unit\Service;

use OCA\DeckGithubSync\Controller\OAuthController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OAuthRouteTest extends TestCase {
	public function testOAuthRedirectRoutesRequireLoginAndExemptCsrf(): void {
		foreach (['start', 'callback'] as $action) {
			$method = new ReflectionMethod(OAuthController::class, $action);
			$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
			$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
			$this->assertCount(0, $method->getAttributes(PublicPage::class));
		}
	}
}
