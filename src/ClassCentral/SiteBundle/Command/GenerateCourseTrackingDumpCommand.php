<?php
/**
 * Created by PhpStorm.
 * User: dhawal
 * Date: 2/21/14
 * Time: 2:57 PM
 */

namespace ClassCentral\SiteBundle\Command;


use ClassCentral\SiteBundle\Entity\CourseStatus;
use ClassCentral\SiteBundle\Entity\UserCourse;
use ClassCentral\SiteBundle\Utility\CourseUtility;
use Doctrine\ORM\Query\ResultSetMapping;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints\DateTime;

class GenerateCourseTrackingDumpCommand extends ContainerAwareCommand{

    protected function configure()
    {
        $this
            ->setName('classcentral:recommender:generatecsvs')
            ->setDescription("Generates csvs required for generating course recommendations");
        ;
    }

    /**
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //$this->generateUserTrackingCSV();

        $this->generateCoursesCSVDetailed();

        //$this->generateUserCoursesCSV();

    }


    private function generateUserTrackingCSV()
    {
        $em = $this->getContainer()->get('doctrine')->getManager();
        $conn = $em->getConnection();
        $tbl = "users_courses_tracking_tmp";
        // Create temporary table from users_courses_tracking wit rows which have user
        // identifier
        $conn->exec("
            CREATE TABLE $tbl
            AS (SELECT * FROM user_courses_tracking WHERE user_identifier != '');
        ");

        // Delete sessions which have just more than 120 courses
        $conn->exec("
            DELETE FROM $tbl WHERE user_identifier IN (SELECT user_identifier FROM (SELECT user_identifier FROM $tbl GROUP BY user_identifier HAVING count(course_id) > 120 ) t);
        ");

//        // Delete sessions which have just 1 course
//        $conn->exec("
//            DELETE FROM $tbl WHERE user_identifier IN (SELECT user_identifier FROM (SELECT user_identifier FROM $tbl GROUP BY user_identifier HAVING count(course_id) = 1 ) t);
//        ");



        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('user_identifier','user_identifier');
        $rsm->addScalarResult('course_id','course_id');
        $rsm->addScalarResult('id','id');
        $id = 0;
        $fp = fopen("extras/user_course.csv", "w");
        while(true)
        {
            $results = $em->createNativeQuery("SELECT id,user_identifier,course_id,created FROM $tbl WHERE id > $id LIMIT 10000", $rsm)->getResult();

            if(empty($results))
            {
                break;
            }
            foreach($results as $userCourse)
            {
                $line = array(
                    $userCourse['user_identifier'],
                    $userCourse['course_id'],
                );
                $id = $userCourse['id'];

                fputcsv($fp,$line);
            }

        }
        fclose($fp);
        // Drop the temporary table
        $conn->exec("DROP TABLE $tbl");

    }

    /**
     * Generate the csv with course id, name, subject
     */
    private function generateCoursesCSV()
    {
        $courses = $this->getContainer()->get('doctrine')->getManager()
                    ->getRepository('ClassCentralSiteBundle:Course')
                    ->findAll();


        $fp = fopen("extras/courses.csv", "w");

        // Add a title line to the CSV
        $title = array(
            'Course Id',
            'Course Name',
            'Provider',
            'Universities/Institutions',
            'Parent Subject',
            'Child Subject',
            'Category',
            'Url',
            'Next Session Date',
            'Length',
            'Language',
            'Video(Url)',
            'Course Description',
            'Credential Name',
            'Created',
            'Status'
        );
        fputcsv($fp,$title);
        $dt = new \DateTime('2016-05-31');
        foreach($courses as $course)
        {
            if($course->getStatus() != CourseStatus::AVAILABLE )
            {
                continue;
            }
            if($course->getCreated() > $dt)
            {
                //continue;
            }

            if(!$course->getIsMooc())
            {
                continue;
            }
            $provider = $course->getInitiative() ? $course->getInitiative()->getName() : "Independent" ;
            $ins = array();
            foreach($course->getInstitutions() as $institution)
            {
                $ins[] = $institution->getName();
            }

            $nextSession = $course->getNextOffering();
            $date = "";
            $url = $course->getUrl();
            if($nextSession)
            {
                $url = $nextSession->getUrl();
                $date = $nextSession->getDisplayDate();
            }

            $subject = $course->getStream();
            if($subject->getParentStream())
            {
                $parent = $subject->getParentStream()->getName();
                $subject = $subject->getName();
            }
            else
            {
                $parent = $subject->getName();
                $subject = "";
            }

            $language = 'English';
            if($course->getLanguage())
            {
                $language = $course->getLanguage()->getName();
            }

            $credential = '';
            if ( !$course->getCredentials()->isEmpty() )
            {
                $cred = $course->getCredentials()->first();
                $credential = $cred->getName();
            }

            $created = null;
            if ($course->getCreated())
            {
                $created = $course->getCreated()->format('Y-m-d');
            }

            $description = $course->getLongDescription();
            if(!$description)
            {
                $description = $course->getDescription();
            }

            $status = '';
            if( $course->getNextOffering() )
            {
                $states = array_intersect( array('past','ongoing','selfpaced','upcoming'), CourseUtility::getStates( $course->getNextOffering() ));
                if(!empty($states))
                {
                    $status = array_pop($states);
                }
            }

            $line = array(
                $course->getId(),
                $course->getName(),
                $provider,
                implode($ins,"|||"),
                $parent,
                $subject,
                $course->getStream()->getName(),
                $url,
                $date,
                $course->getLength(),
                $language,
                $course->getVideoIntro(),
                $course->getDescription(),
                $credential,
                $created,
                $status
            );

            fputcsv($fp,$line);
        }
        fclose($fp);

    }

    private function generateCoursesCSVDetailed()
    {
        $courses = $this->getContainer()->get('doctrine')->getManager()
            ->getRepository('ClassCentralSiteBundle:Course')
            ->findAll();


        $fp = fopen("extras/courses.csv", "w");
        $rs = $this->getContainer()->get('review');

        // Add a title line to the CSV
        $title = array(
            'Course Id',
            'Course Name',
            'Slug',
            'Provider',
            'Universities/Institutions',
            'Parent Subject',
            'Child Subject',
            'Category',
            'Url',
            'Next Session Date',
            'Length',
            'Language',
            'Video(Url)',
            'Course Description',
            'Credential Name',
            'Created',
            'Status',
            'Rating',
            'Number of Ratings',
            'Certificate',
            'Workload'
        );
        fputcsv($fp,$title);

        foreach($courses as $course)
        {
            if($course->getStatus() != CourseStatus::AVAILABLE )
            {
                continue;
            }

            if(!$course->getIsMooc())
            {
                continue;
            }

            $formatter = $course->getFormatter();
            $provider = $course->getInitiative() ? $course->getInitiative()->getName() : "Independent" ;
            $ins = array();
            foreach($course->getInstitutions() as $institution)
            {
                $ins[] = $institution->getName();
            }

            $nextSession = $course->getNextOffering();
            $date = "";
            $url = $course->getUrl();
            if($nextSession)
            {
                $url = $nextSession->getUrl();
                $date = $nextSession->getDisplayDate();
            }

            $subject = $course->getStream();
            if($subject->getParentStream())
            {
                $parent = $subject->getParentStream()->getName();
                $subject = $subject->getName();
            }
            else
            {
                $parent = $subject->getName();
                $subject = "";
            }

            $language = 'English';
            if($course->getLanguage())
            {
                $language = $course->getLanguage()->getName();
            }

            $credential = '';
            if ( !$course->getCredentials()->isEmpty() )
            {
                $cred = $course->getCredentials()->first();
                $credential = $cred->getName();
            }

            $created = null;
            if ($course->getCreated())
            {
                $created = $course->getCreated()->format('Y-m-d');
            }

            $description = $course->getLongDescription();
            if(!$description)
            {
                $description = $course->getDescription();
            }

            $status = '';
            if( $course->getNextOffering() )
            {
                $states = array_intersect( array('past','ongoing','selfpaced','upcoming'), CourseUtility::getStates( $course->getNextOffering() ));
                if(!empty($states))
                {
                    $status = array_pop($states);
                }
            }

            $rating = $rs->getRatings($course->getId());
            $rArray = $rs->getRatingsSummary($course->getId());
            $numRatings = $rArray['count'];

            $line = array(
                $course->getId(),
                $course->getName(),
                $course->getSlug(),
                $provider,
                implode($ins,"|||"),
                $parent,
                $subject,
                $course->getStream()->getName(),
                $url,
                $date,
                $course->getLength(),
                $language,
                $course->getVideoIntro(),
                $course->getDescription(),
                $credential,
                $created,
                $status,
                $rating,
                $numRatings,
                $course->getCertificate(),
                $formatter->getWorkload(),

            );

            fputcsv($fp,$line);
        }
        fclose($fp);

    }

    /**
     * Generate a csv with user_id,course_id, list_id(interested, currently doing)
     */
    private function generateUserCoursesCSV()
    {
        $em = $this->getContainer()->get('doctrine')->getManager();
        $conn = $em->getConnection();


        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('user_id','user_id');
        $rsm->addScalarResult('list_id','list_id');
        $rsm->addScalarResult('course_id','course_id');
        $rsm->addScalarResult('created','created');
        $results = $em->createNativeQuery('SELECT user_id,course_id,list_id,created FROM users_courses', $rsm)->getResult();


        $fp = fopen("extras/user_library.csv", "w");
        foreach($results as $userCourse)
        {
            $line = array(
                $userCourse['user_id'],
                $userCourse['course_id'],
                UserCourse::$lists[$userCourse['list_id']]['slug'],
                $userCourse['created'],
            );

            fputcsv($fp,$line);
        }
        fclose($fp);

    }
} 