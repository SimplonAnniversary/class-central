<?php
/**
 * Created by PhpStorm.
 * User: dhawal
 * Date: 12/31/15
 * Time: 10:46 PM
 */

namespace ClassCentral\SiteBundle\Controller;


use ClassCentral\SiteBundle\Entity\Item;
use ClassCentral\SiteBundle\Utility\UniversalHelper;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class FollowController extends Controller
{

    public function followAction(Request $request, $item,$itemId)
    {
        $em = $this->getDoctrine()->getManager();
        $followService = $this->get('follow');
        $userSession = $this->get('user_session');

        $user = $this->get('security.context')->getToken()->getUser();
        if($user)
        {
            $f = $followService->followUsingItemInfo($user,$item,$itemId);
            if($f)
            {
                // Update User Session
                $userSession->saveFollowInformation();
                return UniversalHelper::getAjaxResponse(true);
            }
            else
            {
                return UniversalHelper::getAjaxResponse (false, "Follow Failed");
            }

        }
        else
        {
            // No logged in user
            return UniversalHelper::getAjaxResponse (false, "User is not logged in");
        }
    }

    public function preFollowAction(Request $request, $item, $itemId)
    {
        $userSession = $this->get('user_session');
        $userSession->saveAnonActivity('follow',"$item-$itemId");
        return UniversalHelper::getAjaxResponse(true);
    }

    public function personalizationAction(Request $request)
    {
        $cache = $this->get('cache');

        $providerController = new InitiativeController();
        $providersData = $providerController->getProvidersList($this->container);

        $insController = new InstitutionController();
        $insData = $insController->getInstitutions($this->container,true);

        $subjectsController = new StreamController();
        $subjects = $cache->get('stream_list_count', array($subjectsController, 'getSubjectsList'),array($this->container));

        $childSubjects = array();
        foreach($subjects['parent'] as $parent)
        {
            if( !empty($subjects['children'][$parent['id']]))
            {
                foreach($subjects['children'][$parent['id']] as $child)
                {
                    $childSubjects[] = $child;
                }
            }
        }

        return  $this->render('ClassCentralSiteBundle:Follow:personalization.html.twig',array(
            'providers' => $providersData['providers'],
            'followProviderItem' => Item::ITEM_TYPE_PROVIDER,
            'institutions' => $insData['institutions'],
            'followInstitutionItem' => Item::ITEM_TYPE_INSTITUTION,
            'page' => 'Personalization',
            'subjects' => $subjects,
            'childSubjects' => $childSubjects,
            'followSubjectItem' => Item::ITEM_TYPE_SUBJECT
        ));
    }
}