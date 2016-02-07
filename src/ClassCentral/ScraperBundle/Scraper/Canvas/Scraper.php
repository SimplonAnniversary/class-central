<?php

namespace ClassCentral\ScraperBundle\Scraper\Canvas;

use ClassCentral\ScraperBundle\Scraper\ScraperAbstractInterface;
use ClassCentral\SiteBundle\Entity\Course;

class Scraper extends ScraperAbstractInterface
{

    const COURSE_CATALOG_URL = 'https://www.canvas.net/products.json?page=%s';

    public function scrape()
    {


        $em = $this->getManager();
        $kuber = $this->container->get('kuber'); // File Api
        $offerings = array();

        $page = 1;


        while(true)
        {
            $coursesUrl = sprintf(self::COURSE_CATALOG_URL,$page);
            $courses = json_decode(file_get_contents($coursesUrl),true);
            if(empty($courses['products']))
            {
                // No more new courses
                break;
            }

            foreach($courses['products'] as $canvasCourse)
            {
                //$this->output->writeLn( $canvasCourse['title'] );
                if( !$canvasCourse['free'] )
                {
                    // Skip paid courses.
                    continue;
                }

                $c = $this->getCourse( $canvasCourse );
                $dbCourse = null;
                $dbCourseFromSlug = $this->dbHelper->getCourseByShortName( $c->getShortName() );
                if( $dbCourseFromSlug  )
                {
                    $dbCourse = $dbCourseFromSlug;
                }
                else
                {
                    $dbCourseFromName = $this->dbHelper->findCourseByName($c->getName(), $this->initiative );
                    if($dbCourseFromName)
                    {
                        $dbCourse = $dbCourseFromName;
                    }
                }

                if( empty($dbCourse) )
                {
                    // New Course
                    $this->out("NEW COURSE - " . $c->getName());
                }

            }

            $page++;
        }

        return $offerings;

    }

    public function getCourse($canvasCourse)
    {
        $dbLanguageMap = $this->dbHelper->getLanguageMap();

        $course = new Course();
        $course->setName( $canvasCourse['title'] );
        $course->setInitiative($this->initiative);
        $course->setDescription( $canvasCourse['teaser'] );
        $course->setUrl( $canvasCourse['url'] );
        $course->setLanguage( $dbLanguageMap['English']);
        $course->setStream(  $this->dbHelper->getStreamBySlug('cs') ); // Default to Computer Science
        $course->setShortName( 'canvas_' . $this->getSlug( $canvasCourse['path']) );

        return $course;
    }

    /**
     * Remove the session number from the path and returns the session slug.
     * i.e discover-your-value-10 will turn into discover-your-value
     * @param $path
     */
    private function getSlug( $path )
    {
        $sessionNumber = substr(strrchr($path,'-'),1);
        if ( !empty($sessionNumber) && is_numeric($sessionNumber) )
        {
            // slice the session number from the path
            return substr($path,0, strrpos($path,'-'));
        }

        return $path;
    }
}