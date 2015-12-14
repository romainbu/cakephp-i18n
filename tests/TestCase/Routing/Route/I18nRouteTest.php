<?php
namespace ADmad\I18n\Test\Routing\Route;

use ADmad\I18n\Routing\Route\I18nRoute;
use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;

/**
 * Tests for I18nRoute.
 */
class I18nRouteTest extends TestCase
{
    /**
     * setUp method.
     *
     * @return void
     */
    public function setUp()
    {
        Configure::write('I18n.languages', ['en', 'fr', 'de']);

        Router::reload();
    }

    /**
     * Test constructor.
     *
     * @return void
     */
    public function testConstructor()
    {
        $route = new I18nRoute('/:controller/:action');
        $this->assertEquals('/:lang/:controller/:action', $route->template);
        $this->assertEquals([], $route->defaults);
        $this->assertEquals(
            [
                'lang' => 'en|fr|de',
                'inflect' => 'dasherize',
                'persist' => ['lang'],
            ],
            $route->options
        );

        $route = new I18nRoute('/');
        $this->assertEquals('/:lang', $route->template);

        $route = new I18nRoute('/:controller/:action', [], ['lang' => 'fr|es']);
        $this->assertEquals('fr|es', $route->options['lang']);

        $route = new I18nRoute('/prefix/:lang/:controller');
        $this->assertEquals('/prefix/:lang/:controller', $route->template);
    }
}
