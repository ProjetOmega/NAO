<?php

namespace Omega\NAOBundle\Controller;

use Facebook\Exceptions\FacebookResponseException;
use Facebook\Facebook;
use Facebook\FacebookSDKException;
use Omega\NAOBundle\Entity\Utilisateurs;
use Omega\NAOBundle\Form\RechercheType;
use Omega\NAOBundle\Services\FacebookLoginService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Omega\NAOBundle\Entity\Observations;
use Omega\NAOBundle\Form\ObservationsType;

use Symfony\Component\Security\Core\SecurityContext;
use Omega\NAOBundle\Form\UtilisateursType;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;



class DefaultController extends Controller
{
    public function indexAction()
    {
        $nbCompte = null;
        $nbObs = null;

        $security = $this->get('security.authorization_checker');

        if($security->isGranted('ROLE_ADMIN'))
        {
            $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Utilisateurs');
            $nbCompte = $repository->countCompte(); 
        }

        if($security->isGranted('ROLE_NATURALISTE') OR $security->isGranted('ROLE_ADMIN'))
        {
            $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Observations');
            $nbObs = $repository->countObsNotVerifie();
        }
        
        return $this->render('OmegaNAOBundle:Default:index.html.twig', array('nbCompte' => $nbCompte, 'nbObs' => $nbObs));
    }

    /**
     * @Security("has_role('ROLE_PARTICULIER')")
     */
    public function addObservationAction(Request $request)
    {
    	$observation = new Observations();
    	$form = $this->get('form.factory')->create(ObservationsType::class, $observation); // Création du form

    	$noms = $this->get('omega_nao.datataxref')->getData(); // On récupère les données pour l'autocomplétion

    	if($request->isMethod('POST') && $form->handleRequest($request)->isValid())
    	{
    		$this->get('omega_nao.upload')->upload($observation); // service d'upload d'image

            $user = $this->getUser(); // On récupère le user courant et on le relie à l'observation
            $observation->setUtilisateur($user);

            $roles = $user->getRoles(); // Si le user est naturaliste
            if($roles == array('ROLE_NATURALISTE') OR $roles == array('ROLE_ADMIN'))
            {
                $observation->setVerifie(true); //On valide directement son observation
            }
    		
    		$em = $this->getDoctrine()->getManager();
    		$em->persist($observation);
    		$em->flush();

            if($roles == array('ROLE_PARTICULIER'))
            {
                $this->get('session')->getFlashBag()->add('success', 'Votre observation a bien été prise en compte, et est désormais en attente de modération.');
            }
            else if($roles = array('ROLE_NATURALISTE'))
            {
                $this->get('session')->getFlashBag()->add('success', 'Votre observation a bien été ajoutée');
            }

    		return $this->redirectToRoute('omega_nao_add_observation');
    	}

    	return $this->render('OmegaNAOBundle:Observations:add.html.twig', array('form' => $form->createView(),
    		'noms' => $noms
    	));
    }

    public function moderationCompteAction()
    {
        $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Utilisateurs');
        $comptes = $repository->getCompte();

        return $this->render('OmegaNAOBundle:Moderation:compte.html.twig', array('comptes' => $comptes));

    }

    public function acceptCompteAction($id)
    {
        $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Utilisateurs');
        $compte = $repository->find($id);

        if($compte == null)
        {
            throw new NotFoundHttpException("Le compte n'existe pas");
        }

        $compte->setVerifie(true);
        $compte->setRoles(array('ROLE_NATURALISTE'));

        $emailBody = $this->renderView('OmegaNAOBundle:Default:mailAcceptCompte.html.twig', array('name' => $compte->getUsername()));
        $subject = "Validation du compte";

        $em = $this->getDoctrine()->getManager();
        $em->persist($compte);
        $em->flush();

        
        $mail = $this->container->get('NAOBundle.mail');
        $mail->getMailService($emailBody, $compte->getEmail(), $subject);


        $this->get('session')->getFlashBag()->add('success', 'Le compte a bien été validé en tant que naturaliste.');

        return $this->redirectToRoute('omega_nao_moderation_compte');
    }

    public function refusedCompteAction($id)
    {
        $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Utilisateurs');
        $compte = $repository->find($id);

        if($compte == null)
        {
            throw new NotFoundHttpException("Le compte n'existe pas");
        }

        $compte->setCompte('particulier');

        $emailBody = $this->renderView('OmegaNAOBundle:Default:mailRefusedCompte.html.twig', array('name' => $compte->getUsername()));
        $subject = "Votre demande a été rejetée";

        $em = $this->getDoctrine()->getManager();
        $em->persist($compte);
        $em->flush();

        $mail = $this->container->get('NAOBundle.mail');
        $mail->getMailService($emailBody, $compte->getEmail(), $subject);

        $this->get('session')->getFlashBag()->add('success', 'Le compte a bien été refusé. Le type du compte a été basculé en particulier.');

        return $this->redirectToRoute('omega_nao_moderation_compte');

    }

    public function moderationObsAction()
    {
        $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Observations');
        $observations = $repository->getObservationParticulier();

        return $this->render('OmegaNAOBundle:Moderation:observation.html.twig', array('observations' => $observations));
    }

    public function acceptObsAction($id)
    {
        $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Observations');
        $observation = $repository->find($id);

        if($observation == null)
        {
            throw new NotFoundHttpException("L'observation n'existe pas");
        }

        $observation->setVerifie(true);

        $emailBody = $this->renderView('OmegaNAOBundle:Default:mailAcceptObs.html.twig', array('name' => $observation->getUtilisateur()->getUsername()));
        $subject = "Validation de votre observation";

        $em = $this->getDoctrine()->getManager();
        $em->persist($observation);
        $em->flush();

        $mail = $this->container->get('NAOBundle.mail');
        $mail->getMailService($emailBody, $observation->getUtilisateur()->getEmail(), $subject);

        $this->get('session')->getFlashBag()->add('success', "L'observation a bien été validée.");

        return $this->redirectToRoute('omega_nao_moderation_observation');   
    }

    public function deleteObsAction($id)
    {
        $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Observations');
        $observation = $repository->find($id);

        if($observation == null)
        {
            throw new NotFoundHttpException("L'observation n'existe pas");
        }

        $emailBody = $this->renderView('OmegaNAOBundle:Default:mailDeleteObs.html.twig', array('name' => $observation->getUtilisateur()->getUsername()));
        $subject = "Observation refusée";

        $em = $this->getDoctrine()->getManager();
        $em->remove($observation);

        if($observation->getPhoto() != null)
        {
            $this->get('omega_nao.upload')->remove($observation);
        }

        $em->flush();

        $mail = $this->container->get('NAOBundle.mail');
        $mail->getMailService($emailBody, $observation->getUtilisateur()->getEmail(), $subject);

        $this->get('session')->getFlashBag()->add('success', "L'observation a bien été supprimée.");

        return $this->redirectToRoute('omega_nao_moderation_observation');
    }

    public function loginAction(Request $request)
    {
        //Facebook inscription//////////////////////////////////////////////////////////////////////////////////////////
        ////////////////////////////////////////////////////////////////////////////////////////////////////////////////
        //v.5.x  ///////////////////////////////////////////////////////////////////////////////////////////////////////
        $fb = new Facebook([
            'app_id' => '164427444154033', // Replace {app-id} with your app id
            'app_secret' => 'd50ce35719164703e0941dc134283aed',
            'default_graph_version' => 'v2.4',
        ]);

        $helper = $fb->getRedirectLoginHelper();

        $permissions = ['email']; // Optional permissions
        $loginUrl = $helper->getLoginUrl('http://localhost/NAO/web/app_dev.php/login', $permissions);

        if ($request->query->get('code'))
        {
            $user = $this->container->get('NAOBundle.FacebookLogin');
            $varibaleFB = $user->getConnect('connexion');

            //var_dump($varibaleFB);
            $request->query->set('code', $varibaleFB);
            $authenticationUtils = $this->get('security.authentication_utils');

            return $this->render('OmegaNAOBundle:Utilisateurs:login.html.twig', array(
                'id' => $varibaleFB, 'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(), 'url' => $loginUrl));

        }

        // Si le visiteur est déjà identifié, on le redirige vers l'accueil
        if ($this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return $this->redirectToRoute('omega_nao_homepage');
        }
        // Le service authentication_utils permet de récupérer le nom d'utilisateur
        // et l'erreur dans le cas où le formulaire a déjà été soumis mais était invalide
        // (mauvais mot de passe par exemple)
        $authenticationUtils = $this->get('security.authentication_utils');

        return $this->render('OmegaNAOBundle:Utilisateurs:login.html.twig', array(
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(), 'url' => $loginUrl
        ));
    }

    public function inscriptionAction (Request $request)
    {
        $inscription = new Utilisateurs();
        $formInscription = $this->get('form.factory')->create(UtilisateursType::class, $inscription);
        $em = $this->getDoctrine()->getManager();

        //Facebook inscription//////////////////////////////////////////////////////////////////////////////////////////
        ////////////////////////////////////////////////////////////////////////////////////////////////////////////////
        //v.5.x  ///////////////////////////////////////////////////////////////////////////////////////////////////////
        $fb = new Facebook([
            'app_id' => '164427444154033', // Replace {app-id} with your app id
            'app_secret' => 'd50ce35719164703e0941dc134283aed',
            'default_graph_version' => 'v2.4',
        ]);

        $helper = $fb->getRedirectLoginHelper();

        $permissions = ['email']; // Optional permissions
        $loginUrl = $helper->getLoginUrl('http://localhost/NAO/web/app_dev.php/inscription', $permissions);
        $urlFB = "";
        if (!$request->query->get('code'))
        {
            $urlFB = $loginUrl;
        }
        if ($request->query->get('code'))
        {
            $user = $this->container->get('NAOBundle.FacebookLogin');
            $user->getConnect('inscription');

            $authenticationUtils = $this->get('security.authentication_utils');

            return $this->render('OmegaNAOBundle:Default:index.html.twig');
        }

        ////////////////////////////////////////////////////////////////////////////////////////////////////////////////

        elseif ($request->isMethod('POST') && $formInscription->handleRequest($request)->isValid())
        {
            $emailBody = $this->renderView('OmegaNAOBundle:Default:bodyMail.html.twig');
            $subject = 'Votre compte a bien été enregistré';
            $inscription->setSalt('');
            $inscription->setRoles(array('ROLE_PARTICULIER'));
            $em->persist($inscription);
            $em->flush();

            $mailerService = $this->container->get('NAOBundle.mailInscription');
            $mailerService->getMailInscriptionService($emailBody, $inscription->getEmail());
        }

        return $this->render('OmegaNAOBundle:Utilisateurs:inscription.html.twig', array('formInscription' => $formInscription->createView(), 'url' =>$urlFB));
    }

    public function inscriptionGoogleAction(Request $request)
    {

        if($request->isXMLHttpRequest())
        {
            $lastname = $request->get('lastname');
            $firstname = $request->get('firstname');
            $email = $request->get('email');
            $googleId = $request->get('id');

            $username = $lastname.''.$firstname;
            $password = uniqid();

            $inscription = new Utilisateurs();
            $inscription->setNom($lastname)
                        ->setUsername($username)
                        ->setEmail($email)
                        ->setPassword($password)
                        ->setCompte('particulier')
                        ->setRoles(array('ROLE_PARTICULIER'))
                        ->setSalt('')
                        ->setGoogleId($googleId)
            ;
            $em = $this->getDoctrine()->getManager();
            $em->persist($inscription);
            $em->flush();

            $emailBody = $this->renderView('OmegaNAOBundle:Default:bodyMail.html.twig');
            $subject = 'Votre compte a bien été enregistré';
            $mailerService = $this->container->get('NAOBundle.mail');
            $mailerService->getMailService($emailBody, $email, $subject);

            return $this->redirectToRoute('omega_nao_homepage');

        }

        return new Response('Erreur ce n\'est pas une requête Ajax', 400);
    }

    public function rechercheAction (Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $formRecherche = $this->createForm(RechercheType::class, null);
        $recherche = array();
        $ficheEspece = null;
        $count = 0;
        $countEspeces = 0;
        $noms = $this->get('omega_nao.datataxref')->getData(); // On récupère les données pour l'autocomplétion

        if ($request->isMethod('POST') && $formRecherche->handleRequest($request)->isValid())
        {
            $espece[0] = $formRecherche->getData('espece');
            $recherche = $em->getRepository('OmegaNAOBundle:Observations')->RecupObservation($espece);
            $countRecherche = $em->getRepository('OmegaNAOBundle:Observations')->countObservation($espece);
            $count = (int) $countRecherche;
            $ficheEspece = $em->getRepository('OmegaNAOBundle:Taxref')->RecupEspece($espece);
            $countEspece = $em->getRepository('OmegaNAOBundle:Taxref')->countEspece($espece);
            $countEspeces = (int) $countEspece;
        }

        return $this->render('OmegaNAOBundle:Rechercher:rechercher.html.twig', array('formRecherche' => $formRecherche->createView(),  'recherche'=> $recherche,
                                                                                            'count' => $count, 'ficheEspece' => $ficheEspece, 'countEspece' => $countEspeces, 'noms' => $noms));
    }


    /**
     * @Security("has_role('ROLE_PARTICULIER')")
     */
    public function profilAction()
    {
        $user = $this->getUser();
        $id = $user->getId();
        $username = $user->getUsername();
        $email = $user->getEmail();
        $compte = $user->getCompte();

        return $this->render('OmegaNAOBundle:utilisateurs:profil.html.twig', array('username' => $username, 'email' => $email, 'compte' => $compte, 'id' => $id));
    }

    public function changerTypeCompteAction($id)
    {
        $repository = $this->getDoctrine()->getManager()->getRepository('OmegaNAOBundle:Utilisateurs');
        $utilisateur = $repository->find($id);

        $utilisateur->setCompte('naturaliste');

        $em = $this->getDoctrine()->getManager();
        $em->persist($utilisateur);
        $em->flush();

        $this->get('session')->getFlashBag()->add('infoCompte', "Votre demande de changement de type de compte a été pris en compte. Votre recevrez très prochainement une réponse concernant votre demande. ");           

        return $this->redirectToRoute('omega_nao_profile');
    }

    public function authentificationFB ()
    {
        // Si le visiteur est déjà identifié, on le redirige vers l'accueil
        if ($this->get('security.authorization_checker')->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            return $this->redirectToRoute('omega_nao_homepage');
        }
        // Le service authentication_utils permet de récupérer le nom d'utilisateur
        // et l'erreur dans le cas où le formulaire a déjà été soumis mais était invalide
        // (mauvais mot de passe par exemple)
        $authenticationUtils = $this->get('security.authentication_utils');

        return $this->render('OmegaNAOBundle:Utilisateurs:login.html.twig', array(
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ));
    }

    public function contactAction (Request $request)
    {
        return $this->render('OmegaNAOBundle:Contact:contact.html.twig');
    }
}
