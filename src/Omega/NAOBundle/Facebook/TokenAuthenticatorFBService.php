<?php

    namespace Omega\NAOBundle\Facebook;

    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\Security\Core\User\UserInterface;
    use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;
    use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
    use Symfony\Component\Security\Core\Exception\AuthenticationException;
    use Symfony\Component\Security\Core\User\UserProviderInterface;
    use Doctrine\ORM\EntityManager;
    use Symfony\Component\Routing\RouterInterface;
    use Symfony\Component\HttpFoundation\RedirectResponse;

class TokenAuthenticatorFBService extends AbstractGuardAuthenticator
{
    private $em;
    private $router;

    public function __construct(EntityManager $em, RouterInterface $router)
    {
        $this->em = $em;
        $this->router = $router;
    }



    public function getCredentials(Request $request)
    {
        return $request->query->get('code');
    }
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $user = $this->em->getRepository('OmegaNAOBundle:Utilisateurs')
            ->findOneBy(array('facebookId' => $credentials));

        return $user;
    }
    public function checkCredentials($credentials, UserInterface $user)
    {
        if($user->getFacebookId() == $credentials)
        {
            return true;
        }
        throw new AuthenticationException('Les identifiants sont incorrects');
    }
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        // on success, let the request continue
        return null;
    }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $url = $this->router->generate('inscription');
        return new RedirectResponse($url);
    }
    /**
     * Called when authentication is needed, but it's not sent
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        $data = array(
            // you might translate this message
            'message' => 'Authentication Required'
        );
        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }
    public function supportsRememberMe()
    {
        return false;
    }
}