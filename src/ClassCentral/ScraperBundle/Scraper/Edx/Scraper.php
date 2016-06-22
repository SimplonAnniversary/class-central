<?php

namespace ClassCentral\ScraperBundle\Scraper\Edx;

use ClassCentral\CredentialBundle\Entity\Credential;
use ClassCentral\ScraperBundle\Scraper\ScraperAbstractInterface;
use ClassCentral\SiteBundle\Entity\Course;
use ClassCentral\SiteBundle\Entity\Initiative;
use ClassCentral\SiteBundle\Entity\Offering;
use ClassCentral\SiteBundle\Services\Kuber;
use ClassCentral\SiteBundle\Utility\UniversalHelper;
use GuzzleHttp\Client;

class Scraper extends ScraperAbstractInterface
{
    const BASE_URL = "https://www.edx.org";
    const COURSE_CATALOGUE = "https://www.edx.org/course-list/allschools/allsubjects/allcourses";
    const EDX_COURSE_LIST_CSV = "https://www.edx.org/api/report/course-feed/export";
    const EDX_RSS_API = "https://www.edx.org/api/v2/report/course-feed/rss?page=%s";
    const EDX_CARDS_API = "https://www.edx.org/api/discovery/v1/course_run_cards";
    const EDX_ENROLLMENT_COURSE_DETAIL = 'https://courses.edx.org/api/enrollment/v1/course/%s?include_expired=1'; // Contains pricing information
    const EDX_API_ALL_COURSES_BASE_v1 = 'https://courses.edx.org';
    const EDX_API_ALL_COURSES_PATH_v1 = '/api/courses/v1/courses/?page=%s';
    public STATIC $EDX_XSERIES_GUID = array(15096, 7046, 14906,14706,7191, 13721,13296, 14951, 13251,15861, 15381
        ,15701, 7056
    );
    private $credentialFields = array(
        'Url','Description','Name', 'OneLiner', 'SubTitle'
    );


    private $courseFields = array(
        'Url', 'Description', 'DurationMin','DurationMax', 'Name','LongDescription','VideoIntro','Certificate',
        'CertificatePrice'
    );

    private $offeringFields = array(
        'StartDate', 'EndDate', 'Url'
    );

    /**
     * Using the CSV
     */
    public function scrape()
    {

        if($this->isCredential)
        {
            $this->scrapeCredentials();
            return;
        }


        //$this->buildSelfPacedCourseList();

        $tagService = $this->container->get('tag');

        // Get the course list from the new RSS API

        $page = 0;
        /*
        $fp = fopen("extras/edX_prices.csv", "w");
        fputcsv($fp, array(
            'Course Name', 'Certificate Name', 'Prices(in $)'
        ));
        */

        /**
         * NEW OFFICIAL API
         */
        $current_page = 1;
        $client = new Client([
            'base_uri' => self::EDX_API_ALL_COURSES_BASE_v1,
            'timeout'  => 2.0,
        ]);
        $response = $client->request('GET',sprintf(self::EDX_API_ALL_COURSES_PATH_v1,$current_page) );
        $edxCourses = json_decode($response->getBody(),true);
        $totalPAges = $edxCourses['pagination']['num_pages'];
        while($current_page < $totalPAges)
        {
            $this->out("Retrieving PAGE #" . $current_page);
            $response = $client->request('GET',sprintf(self::EDX_API_ALL_COURSES_PATH_v1,$current_page) );
            $edxCourses = json_decode($response->getBody(),true);
            foreach($edxCourses['results'] as $edxCourse)
            {
                $this->out( $edxCourse['name']);
            }
            $current_page++;
        }

        exit();
        while(true) {

            $page++;
            $this->out("Retrieving PAGE #" . $page);
            $edxCourses = file_get_contents(sprintf(self::EDX_RSS_API,$page));
            $edxCourses = str_replace("course:","course-",$edxCourses);
            $edxCourses = str_replace("staff:","staff-",$edxCourses);
            $simpleXml = simplexml_load_string($edxCourses);
            $edxCourses = json_encode($simpleXml);

            $edxCourses = json_decode( $edxCourses, true);
            if(empty( $edxCourses['channel']['item']))
            {
                break;
            }

        foreach( $edxCourses['channel']['item'] as $edxCourse )
        {
            $em = $this->getManager();
            $course = $this->getCourseEntity( $edxCourse );
            $cTags = array();
            if(is_array( $edxCourse['course-school'] ))
            {
                foreach( $edxCourse['course-school']  as $school)
                {
                    $cTags[] = strtolower($school);
                }
            }
            else
            {
                $cTags[] = strtolower($edxCourse['course-school'] );
            }

            $courseId = $edxCourse['course-id'];

            /**
            $fileName = "/tmp/edx/{$edxCourse['title']}.json";
            if( file_exists($fileName) )
            {
                $productPrices = json_decode(file_get_contents($fileName),true);
            }
            else
            {
                $productPrices =  json_decode(file_get_contents( sprintf(self::EDX_ENROLLMENT_COURSE_DETAIL, $courseId) ),true);
                if(!empty($productPrices))
                {
                    file_put_contents($fileName, json_encode($productPrices));
                }
            }

            foreach( $productPrices['course_modes'] as $mode)
            {
                if($mode['name'] == 'Honor Certificate' || $mode['name'] == 'Audit')
                {
                    continue;
                }
                $this->out( $edxCourse['title'] . '|||' . $mode['name'] . '|||' . $mode['min_price']);
                fputcsv($fp, array(
                    $edxCourse['title'], $mode['name'], $mode['min_price']
                ));
            }
             continue;
             * */

            $dbCourse = $this->dbHelper->getCourseByShortName( $course->getShortName() );

            // Do a fuzzy match on the course title
            if (!$dbCourse)
            {
                $result = $this->findCourseByName( $edxCourse['title'], $this->initiative);
                if( count($result) > 1)
                {
                    $this->out("DUPLICATE ENTRIES FOR: " . $edxCourse['title']);
                    foreach ($result as $item)
                    {
                        $this->out( "COURSE ID" . $item->getId() );
                    }
                    continue;
                }
                else if (count($result) == 1)
                {
                    $dbCourse = $result;
                }
            }

            if( !$dbCourse )
            {

                if($this->doCreate())
                {
                    $this->out("NEW COURSE - " . $course->getName());
                    // NEW COURSE
                    if ($this->doModify())
                    {
                        // Add instructors
                        if(!empty($edxCourse['course-instructors']['course-staff']['staff-name']))
                        {
                            print_r( $edxCourse['course-instructors']['course-staff']['staff-name'] );
                            $insName = $edxCourse['course-instructors']['course-staff']['staff-name'];
                            $course->addInstructor($this->dbHelper->createInstructorIfNotExists($insName));
                        }
                        elseif( !empty( $edxCourse['course-instructors']['course-staff'] ))
                        {
                            foreach( $edxCourse['course-instructors']['course-staff'] as $staff )
                            {
                                $insName = $staff['staff-name'];
                                if(!empty($insName))
                                {
                                    $course->addInstructor($this->dbHelper->createInstructorIfNotExists($insName));
                                }
                            }
                        }

                        $em->persist($course);
                        $em->flush();

                        $tagService->saveCourseTags( $course, $cTags);

                        $this->dbHelper->sendNewCourseToSlack( $course, $this->initiative );

                        if($edxCourse['course-image-banner'])
                        {
                            $this->uploadImageIfNecessary( $edxCourse['course-image-banner'], $course);
                        }



                    }
                }
            }
            else
            {
                // Check if any fields are modified
                $courseModified = false;
                $changedFields = array(); // To keep track of fields that have changed
                foreach($this->courseFields as $field)
                {
                    $getter = 'get' . $field;
                    $setter = 'set' . $field;
                    if($course->$getter() != $dbCourse->$getter())
                    {
                        $courseModified = true;

                        // Add the changed field to the changedFields array
                        $changed = array();
                        $changed['field'] = $field;
                        $changed['old'] =$dbCourse->$getter();
                        $changed['new'] = $course->$getter();
                        $changedFields[] = $changed;

                        $dbCourse->$setter($course->$getter());
                    }

                }

                if($courseModified && $this->doUpdate())
                {
                    //$this->out( "Database course changed " . $dbCourse->getName());
                    // Course has been modified
                    $this->out("UPDATE COURSE - " . $dbCourse->getName() . " - ". $dbCourse->getId());
                    $this->outputChangedFields($changedFields);
                    if ($this->doModify())
                    {
                        $em->persist($dbCourse);
                        $em->flush();

                        // Update tags
                        $tagService->saveCourseTags( $dbCourse, $cTags);

                        if($edxCourse['course-image-banner'])
                        {
                            $this->uploadImageIfNecessary( $edxCourse['course-image-banner'], $dbCourse);
                        }
                    }

                }


                $course = $dbCourse;
            }

            /***************************
             * CREATE OR UPDATE OFFERING
             ***************************/
            $offering = new Offering();
            $osn = $this->getOfferingShortName( $edxCourse );
            $offering->setShortName( $osn );
            $offering->setCourse( $course );
            $offering->setUrl( $edxCourse['link'] );
            $offering->setStatus( Offering::START_DATES_KNOWN );
            $offering->setStartDate( new \DateTime( $edxCourse['course-start'] ) );

            if( empty($edxCourse['course-end']) )
            {
                // Put an end date for 4 weeks in the future
                $endDate = new \DateTime(   $edxCourse['course-start'] );
                $endDate->add(new \DateInterval("P30D") );
            }
            else
            {
                $endDate = new \DateTime(  $edxCourse['course-end'] );
            }
            $offering->setEndDate( $endDate );

            $dbOffering = $this->dbHelper->getOfferingByShortName($osn);

            if (!$dbOffering)
            {
                if($this->doCreate())
                {
                    $this->out("NEW OFFERING - " . $offering->getName());
                    if ($this->doModify())
                    {
                        $em->persist($offering);
                        $em->flush();
                    }
                    $this->dbHelper->sendNewOfferingToSlack( $offering);
                    $offerings[] = $offering;
                }
            }
            else
            {
                // old offering. Check if has been modified or not
                $offeringModified = false;
                $changedFields = array();
                foreach ($this->offeringFields as $field)
                {
                    $getter = 'get' . $field;
                    $setter = 'set' . $field;
                    if ($offering->$getter() != $dbOffering->$getter())
                    {
                        $offeringModified = true;
                        // Add the changed field to the changedFields array
                        $changed = array();
                        $changed['field'] = $field;
                        $changed['old'] =$dbOffering->$getter();
                        $changed['new'] = $offering->$getter();
                        $changedFields[] = $changed;
                        $dbOffering->$setter($offering->$getter());
                    }
                }

                if ($offeringModified && $this->doUpdate())
                {
                    // Offering has been modified
                    $this->out("UPDATE OFFERING - " . $dbOffering->getName());
                    $this->outputChangedFields($changedFields);
                    if ($this->doModify())
                    {
                        $em->persist($dbOffering);
                        $em->flush();
                    }
                    $offerings[] = $dbOffering;

                }
            }

        }
        }
        /**
         fclose($fp);
         */
        return $offerings;

    }

    private function  getOfferingShortName( $c = array() )
    {
        $edxCourseId = $this->getEdxCourseId( $c['guid'] );
        return 'edx_'. $edxCourseId;
    }

    /**
     * Given an array built from edX csv returns a course entity
     * @param array $c
     */
    private function getCourseEntity ($c = array())
    {
        $defaultStream = $this->dbHelper->getStreamBySlug('cs');
        $langMap = $this->dbHelper->getLanguageMap();
        $defaultLanguage = $langMap[ 'English' ];

        $course = new Course();
        $course->setShortName( $this->getShortName($c) );
        $course->setInitiative( $this->initiative );
        $course->setName( $c['course-code'] . ': ' . $c['title'] );
        $course->setDescription( $c['description'] );
        $course->setLongDescription( nl2br($c['description']) );
        $course->setLanguage( $defaultLanguage);
        $course->setStream($defaultStream); // Default to Computer Science
        $course->setVideoIntro( $c['course-video-youtube']);
        $course->setUrl($c['link']);

        $course->setCertificate( $c['course-verified'] );
        if($c['course-verified'])
        {
            // Signify its a paid certificate
            $course->setCertificatePrice( Course::PAID_CERTIFICATE );
        }
        else
        {
            $course->setCertificatePrice( 0 );
        }

        // Calculate length
        $length = null;
        if( !empty($c['course-end']))
        {
            $start = new \DateTime( $c['course-start'] );
            $end = new \DateTime( $c['course-end'] );
            $length = ceil( $start->diff($end)->days/7 );
        }

        $course->setDurationMin($length);
        $course->setDurationMax($length);

        return $course;
    }

    private function getShortName( $details )
    {
        $school = $details['course-school'];
        if(is_array($details['course-school']))
        {
            $school = array_pop( $details['course-school'] );
        }
        return 'edx_' . strtolower( $details['course-code'] . '_' . $school );
    }

    /**
     * Generates the url to embed video for youtube videos
     * @param $videoIntro
     * @return null
     */
    private function  getVideoEmbedUrl($videoIntro)
    {
        if(empty($videoIntro))
        {
            return null;
        }

        $parsedUrl = parse_url($videoIntro);
        if (!isset($parsedUrl['query']))
        {
            return null;
        }
        parse_str($parsedUrl['query'], $getParams);
        if(isset($getParams['v']))
        {
            return 'https://www.youtube.com/watch?v=' .  $getParams['v'];
        }

        return null;
    }


    private function parseCourseCode($str)
    {
        $exploded = explode('/',$str);
        return $exploded[3];
    }

    /**
     * Parses the edX from url.
     * i.e /course/wellesley/hist229x/was-alexander-great-life/850 => 850
     * @param $url
     */
    private function getEdxCourseId($url)
    {
        return substr($url, strrpos($url,'/')+1);
    }

    private function getStartDate($html)
    {
        $dateStr = $html->find("div.course-detail-start",0)->plaintext;
        return substr($dateStr,strrpos($dateStr,':')+1);
    }

    /**
     * Used to print the field values which have been modified for both offering and courses
     * @param $changedFields
     */
    private function outputChangedFields($changedFields)
    {
        foreach($changedFields as $changed)
        {
            $field = $changed['field'];
            $old = is_a($changed['old'], 'DateTime') ? $changed['old']->format('jS M, Y') : $changed['old'];
            $new = is_a($changed['new'], 'DateTime') ? $changed['new']->format('jS M, Y') : $changed['new'];

            $this->out("$field changed from - '$old' to '$new'");
        }
    }

    private function uploadImageIfNecessary( $imageUrl, Course $course)
    {
        $kuber = $this->container->get('kuber');
        $uniqueKey = basename($imageUrl);
        if( $kuber->hasFileChanged( Kuber::KUBER_ENTITY_COURSE,Kuber::KUBER_TYPE_COURSE_IMAGE, $course->getId(),$uniqueKey ) )
        {
            // Upload the file
            $filePath = '/tmp/course_'.$uniqueKey;
            file_put_contents($filePath,file_get_contents($imageUrl));
            $kuber->upload(
                $filePath,
                Kuber::KUBER_ENTITY_COURSE,
                Kuber::KUBER_TYPE_COURSE_IMAGE,
                $course->getId(),
                null,
                $uniqueKey
            );

        }
    }

    /**
     * Tries to find a edx course with the particular title
     * @param $title
     * @param $initiative
     */
    private function findCourseByName ($title, Initiative $initiative)
    {
        $em = $this->getManager();
        $result = $em->getRepository('ClassCentralSiteBundle:Course')->createQueryBuilder('c')
                    ->where('c.initiative = :initiative' )
                    ->andWhere('c.name LIKE :title')
                    ->setParameter('initiative', $initiative)
                    ->setParameter('title', '%'.$title)
                    ->getQuery()
                    ->getResult()
        ;

        if ( count($result) == 1)
        {
            return $result[0];
        }

        return null;
    }


    private function buildSelfPacedCourseList()
    {
        $apiUrl = 'https://www.edx.org/search/api/all';
        $selfPacedCourses = array();
        $allCourses = json_decode( file_get_contents($apiUrl), true );
        foreach( $allCourses as $edXCourse)
        {
            $dbCourse = null;
            if ( $edXCourse['pace'] & $edXCourse['availability'] == 'Current' ) // Self paced courses
            {
                $courseShortName = 'edx_' . strtolower( $edXCourse['code'] . '_' .$edXCourse['schools'][0] );

                $dbCourseFromSlug = $this->dbHelper->getCourseByShortName($courseShortName);
                if( $dbCourseFromSlug  )
                {
                    $dbCourse = $dbCourseFromSlug;
                }
                else
                {
                    $dbCourseFromName = $this->findCourseByName( $edXCourse['l'] , $this->initiative );
                    if($dbCourseFromName)
                    {
                        $dbCourse = $dbCourseFromName;
                    }
                }

                if( empty($dbCourse) )
                {
                    $this->out("OnDemand Course Missing : " .  $edXCourse['l']  );
                }
                else
                {

                    // Check how many of them are self paced
                    $selfPaced = false;
                    if ( $dbCourse->getNextOffering()->getStatus() == Offering::COURSE_OPEN )
                    {
                        $selfPaced = true;
                    }

                    if ( !$selfPaced )
                    {
                        $this->out("OnDemand Session Missing : " . $edXCourse['l'])  ;
                    }
                }

            }
        }
    }

    private function isCourseSelfPaced( $edXCourse )
    {
        if( strpos( $edXCourse['start'], 'Self-paced') !== false )
        {
            return true;
        }
    }

    public function scrapeCredentials()
    {


        foreach(self::$EDX_XSERIES_GUID as $guid)
        {
            $xseries = json_decode(file_get_contents(
                sprintf( 'https://www.edx.org/node/%s.json?deep-load-refs=1',$guid )),
                true);
            $credential = $this->getCredential($xseries);
            $this->saveOrUpdateCredential( $credential, $xseries['field_xseries_banner_image']['file']['uri'] );
        }

        /**
        $edXCourses = json_decode(file_get_contents( 'https://www.edx.org/search/api/all' ),true);
        foreach($edXCourses as $edXCourse)
        {
             if(in_array('xseries',$edXCourse['types']))
             {
                $this->out( $edXCourse['l'] );
                 $guid = $edXCourse['guid'];
                 var_dump($guid);
                 continue;
                 $xseries = json_decode(file_get_contents(
                     sprintf( 'https://www.edx.org/node/%s.json?deep-load-refs=1',$guid )),
                     true);



             }
        }

        return;

        $edXCourses = json_decode(file_get_contents( self::EDX_CARDS_API ),true);
        foreach($edXCourses as $edXCourse)
        {
            if( isset($edXCourse['attributes']['xseries'] ) )
            {
                $this->out($edXCourse['title']);
                $xseriesId = $edXCourse['attributes']['xseries'];

                var_dump(  $edXCourse['attributes'] );
            }
        }
         */
    }


    public function getCredential( $xseries )
    {
        $credential = new Credential();
        $credential->setName( $xseries['title'] );
        $credential->setPricePeriod(Credential::CREDENTIAL_PRICE_PERIOD_TOTAL);
        $credential->setPrice(0);
        $credential->setSlug( UniversalHelper::getSlug( $credential->getName()) . '-xseries'   );
        $credential->setInitiative( $this->initiative );
        $credential->setUrl( $xseries['url'] );
        $credential->setOneLiner( $xseries['field_xseries_subtitle'] );
        $credential->setSubTitle( $xseries['field_xseries_subtitle_short'] );
        $credential->setDescription( $xseries['body']['value'] .  $xseries['field_xseries_overview']['value'] );

        return $credential;
    }

    /**
     * @param Credential $credential
     */
    private function saveOrUpdateCredential(Credential $credential, $imageUrl)
    {
        $dbCredential = $this->dbHelper->getCredentialBySlug( $credential->getSlug() ) ;
        $em = $this->getManager();
        if( !$dbCredential )
        {
            if($this->doCreate())
            {
                $this->out("New Credential - " . $credential->getName() );
                if ($this->doModify())
                {
                    $em->persist( $credential );
                    $em->flush();

                    $this->dbHelper->uploadCredentialImageIfNecessary($imageUrl,$credential);
                }
            }
        }
        else
        {
            // Update the credential
            $changedFields = $this->dbHelper->changedFields($this->credentialFields,$credential,$dbCredential);
            if(!empty($changedFields) && $this->doUpdate())
            {
                $this->out("UPDATE CREDENTIAL - " . $dbCredential->getName() );
                $this->outputChangedFields( $changedFields );
                if ($this->doModify())
                {
                    $em->persist($dbCredential);
                    $em->flush();

                    $this->dbHelper->uploadCredentialImageIfNecessary($imageUrl,$dbCredential);
                }
            }

        }
    }
}