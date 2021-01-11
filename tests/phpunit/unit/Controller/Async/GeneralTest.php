<?php

namespace Bolt\Tests\Controller\Async;

use Bolt\Common\Json;
use Bolt\Controller\Zone;
use Bolt\Response\TemplateView;
use Bolt\Tests\Controller\ControllerUnitTest;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class to test correct operation of src/Controller/Async/General.
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 **/
class GeneralTest extends ControllerUnitTest
{
    /**
     * @covers \Bolt\Controller\Zone::get
     * @covers \Bolt\Controller\Zone::isAsync
     */
    public function testControllerZone()
    {
        $app = $this->getApp();
        $this->allowLogin($app);
        $this->setRequest(Request::create('/async'));
        $request = $this->getRequest();
        $request->cookies->set($app['token.authentication.name'], 'dropbear');

        $kernel = $this->createMock(HttpKernelInterface::class);
        $app['dispatcher']->dispatch(KernelEvents::REQUEST, new GetResponseEvent($kernel, $request, HttpKernelInterface::MASTER_REQUEST));

        $this->assertEquals('async', Zone::get($request));
        $this->assertTrue(Zone::isAsync($request));
    }

    public function testAsyncBaseRoute()
    {
        $app = $this->getApp();
        $this->allowLogin($app);
        $this->setRequest(Request::create('/async'));

        $response = $this->controller()->async();

        $this->assertJson($response->getContent());
        $this->assertEquals('["OK"]', $response->getContent());
    }

    public function testChangeLogRecord()
    {
        $this->setRequest(Request::create('/async/changelog/page/1'));

        $response = $this->controller()->changeLogRecord('pages', 1);

        $this->assertTrue($response instanceof TemplateView);
        $this->assertSame('@bolt/components/panel-change-record.twig', $response->getTemplate());
    }

    public function testDashboardNewsWithInvalidRequest()
    {
        $this->setRequest(Request::create('/async/dashboardnews'));
        $app = $this->getApp();
        $testGuzzle = $this->getMockGuzzleClient();
        $requestInterface = $this->createMock(RequestInterface::class);
        $testGuzzle->expects($this->at(0))->method('get')->will($this->throwException(new RequestException('Mock Fail', $requestInterface)));

        $this->setService('guzzle.client', $testGuzzle);

        $logger = $this->getMockLoggerManager();
        $logger->expects($this->at(1))
            ->method('error')
            ->with($this->stringContains('Error occurred'));
        $this->setService('logger.system', $logger);
        $this->controller()->dashboardNews($this->getRequest());
    }

    public function testDashboardNewsWithInvalidJson()
    {
        $this->setRequest(Request::create('/async/dashboardnews'));
        $app = $this->getApp();
        $app['cache']->flushAll();
        $testGuzzle = $this->getMockGuzzleClient();
        $testRequest = $this->createMock(RequestInterface::class);
        $testGuzzle->expects($this->any())
            ->method('get')
            ->will($this->returnValue($testRequest))
        ;
        $this->setService('guzzle.client', $testGuzzle);

        $logger = $this->getMockLoggerManager();
        $logger->expects($this->at(1))
            ->method('error')
            ->with($this->stringContains('Invalid JSON'));
        $this->setService('logger.system', $logger);
        $this->controller()->dashboardNews($this->getRequest());
    }

    public function testDashboardNewsWithVariable()
    {
        $app = $this->getApp();
        $app['cache']->flushAll();
        $this->setRequest(Request::create('/async/dashboardnews'));
        $app['config']->set('general/branding/news_variable', 'testing');

        $testGuzzle = $this->getMockGuzzleClient();
        $requestInterface = $this->createMock(RequestInterface::class);
        $requestInterface
            ->method('getBody')
            ->will($this->returnValue('{"testing":[{"item":"one"},{"item":"two"},{"item":"three"}]}'))
        ;
        $testGuzzle->expects($this->any())
            ->method('get')
            ->will($this->returnValue($requestInterface))
        ;
        $this->setService('guzzle.client', $testGuzzle);

        $response = $this->controller()->dashboardNews($this->getRequest());

        $context = $response->getContext();
        $this->assertEquals(['item' => 'one'], (array) $context['context']['information']);
    }

    public function testDashboardNews()
    {
        $this->setRequest(Request::create('/async/dashboardnews'));

        $response = $this->controller()->dashboardNews($this->getRequest());
        $this->assertTrue($response instanceof TemplateView);
        $this->assertSame('@bolt/components/panel-news.twig', $response->getTemplate());
    }

    public function testLastModified()
    {
        $this->setRequest(Request::create('/async/lastmodified/page/1'));

        $response = $this->controller()->lastModified('page', 1);

        $this->assertTrue($response instanceof TemplateView);
        $this->assertSame('@bolt/components/panel-lastmodified.twig', $response->getTemplate());
    }

    public function testLatestactivity()
    {
        $this->setRequest(Request::create('/async/latestactivity'));

        $response = $this->controller()->latestActivity();

        $this->assertTrue($response instanceof TemplateView);
        $this->assertSame('@bolt/components/panel-activity.twig', $response->getTemplate());
    }

    /**
     * @covers \Bolt\Storage::getUri
     * @covers \Bolt\Controller\Async\General::makeUri
     */
    public function testMakeUri()
    {
        // Set up a fake request for getContent()'s sake
        $this->setRequest(Request::create('/'));
        $record = $this->getService('storage')->getContent('pages/1');
        $this->setRequest(Request::create('/async/makeuri', 'GET', [
            'title'           => $record->values['title'],
            'id'              => $record->values['id'],
            'contenttypeslug' => 'pages',
            'fulluri'         => true,
        ]));

        $response = $this->controller()->makeUri($this->getRequest());

        $this->assertSame('/page/' . $record->values['slug'], $response);
    }

    public function testOmnisearch()
    {
        $this->setRequest(Request::create('/async/omnisearch', 'GET', [
            'q' => 'sho',
        ]));

        $response = $this->controller()->omnisearch($this->getRequest());

        $this->assertTrue($response instanceof JsonResponse);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $json = Json::parse($response->getContent());

        $this->assertSame('Omnisearch', $json[0]['label']);
        $this->assertSame('New Showcase', $json[1]['label']);
        $this->assertSame('View Showcases', $json[2]['label']);
    }

    public function testPopularTags()
    {
        $this->setRequest(Request::create('/async/populartags'));

        $response = $this->controller()->popularTags($this->getRequest(), 'tags');

        $this->assertTrue($response instanceof JsonResponse);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $json = Json::parse($response->getContent());
        $tags = $this->getDefaultTags();

        $this->assertCount(20, $json);
        $this->assertTrue(in_array($json[0]['name'], $tags));
    }

//    public function testTags()
//    {
//         $this->setRequest(Request::create('/async/tags/tags'));
//         $response = $this->controller()->tags($this->getRequest(), 'tags');
//
//         $this->assertTrue($response instanceof JsonResponse);
//         $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
//
//         $json = Json::parse($response->getContent());
//         $tags = $this->getDefaultTags();
//
//         $this->assertCount(20, $json);
//         $this->assertTrue(in_array($json[0]['name'], $tags));
//    }

    /**
     * @return \Bolt\Controller\Async\General
     */
    protected function controller()
    {
        return $this->getService('controller.async.general');
    }

    private function getDefaultTags()
    {
        return ['action', 'adult', 'adventure', 'alpha', 'animals', 'animation', 'anime', 'architecture', 'art',
            'astronomy', 'baby', 'batshitinsane', 'biography', 'biology', 'book', 'books', 'business', 'business',
            'camera', 'cars', 'cats', 'cinema', 'classic', 'comedy', 'comics', 'computers', 'cookbook', 'cooking',
            'crime', 'culture', 'dark', 'design', 'digital', 'documentary', 'dogs', 'drama', 'drugs', 'education',
            'environment', 'evolution', 'family', 'fantasy', 'fashion', 'fiction', 'film', 'fitness', 'food',
            'football', 'fun', 'gaming', 'gift', 'health', 'hip', 'historical', 'history', 'horror', 'humor',
            'illustration', 'inspirational', 'internet', 'journalism', 'kids', 'language', 'law', 'literature', 'love',
            'magic', 'math', 'media', 'medicine', 'military', 'money', 'movies', 'mp3', 'murder', 'music', 'mystery',
            'news', 'nonfiction', 'nsfw', 'paranormal', 'parody', 'philosophy', 'photography', 'photos', 'physics',
            'poetry', 'politics', 'post-apocalyptic', 'privacy', 'psychology', 'radio', 'relationships', 'research',
            'rock', 'romance', 'rpg', 'satire', 'science', 'sciencefiction', 'scifi', 'security', 'self-help',
            'series', 'software', 'space', 'spirituality', 'sports', 'story', 'suspense', 'technology', 'teen',
            'television', 'terrorism', 'thriller', 'travel', 'tv', 'uk', 'urban', 'us', 'usa', 'vampire', 'video',
            'videogames', 'war', 'web', 'women', 'world', 'writing', 'wtf', 'zombies', ];
    }
}
