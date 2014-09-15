<?php

use Mockery as m;

class TalkControllerTest extends PHPUnit_Framework_TestCase
{
    protected $app;
    protected $req;

    public function setup()
    {
        $bs = new OpenCFP\Bootstrap();
        $this->app = $bs->getApp();

        // Override things so that Spot2 is using in-memory tables
        $cfg = new \Spot\Config;
        $cfg->addConnection('sqlite', [
            'dbname' => 'sqlite::memory',
            'driver' => 'pdo_sqlite'
        ]);
        $this->app['spot'] = new \Spot\Locator($cfg);

        // Initialize the talk table in the sqlite database
        $talk_mapper = $this->app['spot']->mapper('OpenCFP\Entity\Talk');
        $talk_mapper->migrate();

        // Set things up so Sentry believes we're logged in
        $user = m::mock('StdClass');
        $user->shouldReceive('getId')->andReturn(uniqid());
        $user->shouldReceive('getLogin')->andReturn(uniqid() . '@grumpy-learning.com');

        // Create a test double for Sentry
        $sentry = m::mock('StdClass');
        $sentry->shouldReceive('check')->andReturn(true);
        $sentry->shouldReceive('getUser')->andReturn($user);
        $this->app['sentry'] = $sentry;

        // Create a test double for sessions so we can control what happens
        $this->app['session'] = new SessionDouble();

        // Create our test double for the request object
        $this->req = m::mock('Symfony\Component\HttpFoundation\Request');
    }

    /**
     * Verify that talks with ampersands and other characters in them can
     * be created and then edited properly
     *
     * @test
     */
    public function ampersandsAcceptableCharacterForTalks()
    {
        $controller = new OpenCFP\Controller\TalkController();

        // Get our request object to return expected data
        $talk_data = [
            'title' => 'Test Title With Ampersand',
            'description' => "The title should contain this & that",
            'type' => 'regular',
            'level' => 'entry',
            'category' => 'other',
            'desired' => 0,
            'slides' => '',
            'other' => '',
            'sponsor' => '',
            'user_id' => $this->app['sentry']->getUser()->getId()
        ];

        $this->setPost($talk_data);

        /**
         * If the talk was successfully created, a success value is placed
         * into the session flash area for display
         */
        $create_response = $controller->processCreateAction($this->req, $this->app);
        $create_flash = $this->app['session']->get('flash');
        $this->assertEquals($create_flash['type'], 'success');

        // Now, edit the results and update them
        $talk_data['id'] = 1;
        $talk_data['description'] = "The title should contain this & that & this other thing";
        $talk_data['title'] = "Test Title With Ampersand & More Things";
        $this->setPost($talk_data);

        $update_response = $controller->updateAction($this->req, $this->app);
        $update_flash = $this->app['session']->get('flash');
        $this->assertEquals(
            $update_flash['type'],
            'success'
        );
    }

    /**
     * Make sure that the edit action loads the expected talk and displays
     * the correct information
     *
     * @test
     */
    public function editActionDisplaysFormWithCorrectFieldValues()
    {
        $this->req->shouldReceive('get')->with('id')->andReturn(1);

        // Create a talk for us to retrieve
        $talk_mapper = $this->app['spot']->mapper('OpenCFP\Entity\Talk');
        $talk_data = [
            'title' => 'Edit Action Talk',
            'description' => 'This is a longer description of a talk',
            'type' => 'regular',
            'level' => 'Mid-level',
            'category' => 'testing',
            'user_id' => $this->app['sentry']->getUser()->getId()
        ];
        $talk_mapper->create($talk_data);

        // Grab our controller object and run the action
        $controller = new OpenCFP\Controller\TalkController();
        $response = $controller->editAction($this->req, $this->app);

        // Do we have a form
        $this->assertContains("<form", $response, "Could not find a form");

        // Do we have some of the form fields we are expecting
        $this->assertContains('<input id="form-talk-title"', $response);
        $this->assertContains('<select id="form-talk-type" name="type">', $response);
        $this->assertContains('<button type="submit"', $response);

        // Do we have data in the form fields?
        $this->assertContains($talk_data['title'], $response);
        $this->assertContains($talk_data['description'], $response);
        $this->assertContains($talk_data['type'], $response);
        $this->assertContains($talk_data['level'], $response);
        $this->assertContains($talk_data['category'], $response);

    }

    /**
     * Verify that you cannot edit talks that do not belong to you
     *
     * @test
     */
    public function cannotEditTalksThatDoNotBelongToUser()
    {
        $this->req->shouldReceive('get')->with('id')->andReturn(1);

        // Create a talk for us to retrieve
        $talk_mapper = $this->app['spot']->mapper('OpenCFP\Entity\Talk');
        $talk_data = [
            'title' => 'Edit Action Talk',
            'description' => 'This is a longer description of a talk',
            'type' => 'regular',
            'level' => 'Mid-level',
            'category' => 'testing',
            'user_id' => 0
        ];
        $talk_mapper->create($talk_data);

        // Grab our controller object and run the action
        $controller = new OpenCFP\Controller\TalkController();
        $response = $controller->editAction($this->req, $this->app);

        // Response should be you redirected to the dashboard
        $this->assertEquals(
            'Symfony\Component\HttpFoundation\RedirectResponse',
            get_class($response),
            "Did not get redirected as expected"
        );
    }


    /**
     * Method for setting the values that would be posted to a controller
     * action
     *
     * @param mixed $data
     * @return void
     */
    protected function setPost($data)
    {
        foreach ($data as $key => $value) {
            $this->req->shouldReceive('get')->with($key)->andReturn($value);
        }
    }
}

class SessionDouble extends Symfony\Component\HttpFoundation\Session\Session
{
    protected $flash;

    public function get($value, $default = null)
    {
        return $this->$value;
    }

    public function set($name, $value)
    {
        $this->$name = $value;
    }
}
