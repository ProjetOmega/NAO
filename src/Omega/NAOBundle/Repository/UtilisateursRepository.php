<?php

namespace Omega\NAOBundle\Repository;

/**
 * UtilisateursRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UtilisateursRepository extends \Doctrine\ORM\EntityRepository
{
	public function getCompte()
	{
		$qb = $this->createQueryBuilder('u');
		$qb
		 ->where('u.compte = :compte')
		 ->setParameter('compte', 'naturaliste')
		 ->andWhere('u.verifie = :verifie')
		 ->setParameter('verifie', false)
		 ;

		 return $qb
		 	->getQuery()
		 	->getResult()
		 ;
	}

    public function countComptes ()
    {
        $qb = $this->createQueryBuilder('a')
            ->select('count(a)');

        return $qb  ->getQuery()
            ->getSingleScalarResult();

    }

	public function countCompte()
	{
		$qb = $this->createQueryBuilder('u');
		$qb
		 ->select('COUNT(u)')
		 ->where('u.compte = :compte')
		 ->setParameter('compte', 'naturaliste')
		 ->andWhere('u.verifie = :verifie')
		 ->setParameter('verifie', false)
		 ;

		 return $qb
		 		 ->getQuery()
		 		 ->getSingleScalarResult()
		 		 ;
	}

}
