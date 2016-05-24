<?php

namespace Wealthbot\AdminBundle\Repository;

use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\EntityRepository;
use Wealthbot\AdminBundle\Entity\SecurityAssignment;
use Wealthbot\AdminBundle\Entity\Subclass;
use Wealthbot\UserBundle\Entity\User;

/**
 * SubclassRepository.
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class SubclassRepository extends EntityRepository
{
    /**
     * Get expected performance for user subclasses or admin subclasses if $userId is null.
     *
     * @param $modelId
     * @param bool $isQualified
     * @param null $userId
     *
     * @return array
     */
    public function getExpectedPerformanceAsArray($modelId, $isQualified = false, $userId = null)
    {
        $result = [];

        if (null === $userId) {
            $connection = $this->getEntityManager()->getConnection();

            $sql = 'SELECT s.* FROM subclasses s
                    LEFT JOIN portfolio_model_entities pme ON (pme.subclass_id = s.id)
                    WHERE pme.portfolio_model_id = :model_id AND (s.owner_id IS NULL) AND s.source_id IS NULL
                    AND pme.is_qualified = :is_qualified';

            $stmt = $connection->prepare($sql);
            $stmt->bindValue('model_id', $modelId);
            $stmt->bindValue('is_qualified', $isQualified);
            $stmt->execute();

            foreach ($stmt->fetchAll() as $subclass) {
                $result[$subclass['id']] = $subclass['expected_performance'];
            }
        } else {
            $result = $this->getUserExpectedPerformanceAsArray($userId, $modelId, $isQualified);
        }

        return $result;
    }

    private function getUserExpectedPerformanceAsArray($userId, $modelId, $isQualified)
    {
        $connection = $this->getEntityManager()->getConnection();
        $result = [];

        $sql = 'SELECT s.* FROM subclasses s
                LEFT JOIN portfolio_model_entities pme ON (pme.subclass_id = s.id)
                WHERE pme.portfolio_model_id = :model_id AND s.owner_id = :owner_id AND s.source_id IS NULL
                AND pme.is_qualified = :is_qualified';

        $stmt = $connection->prepare($sql);
        $stmt->bindValue('owner_id', $userId);
        $stmt->bindValue('model_id', $modelId);
        $stmt->bindValue('is_qualified', $isQualified);

        if (!$stmt->execute()) {
            $sql = 'SELECT s.* FROM subclasses s
                    LEFT JOIN subclasses orig_s ON (s.source_id = orig_s.id)
                    LEFT JOIN portfolio_model_entities pme ON (pme.subclass_id = orig_s.id)
                    WHERE pme.portfolio_model_id = :model_id AND s.owner_id = :owner_id
                    AND pme.is_qualified = :is_qualified';

            $stmt = $connection->prepare($sql);
            $stmt->bindValue('owner_id', $userId);
            $stmt->bindValue('model_id', $modelId);
            $stmt->bindValue('is_qualified', $isQualified);
            $stmt->execute();
        }

        foreach ($stmt->fetchAll() as $subclass) {
            $result[$subclass['id']] = $subclass['expected_performance'];
        }

        return $result;
    }

    public function findAdminSubclasses()
    {
        $qb = $this->createQueryBuilder('s');
        $qb->where('s.owner_id IS NULL');

        return $qb->getQuery()->getResult();
    }

    public function findByOwnerId($ownerId)
    {
        $qb = $this->createQueryBuilder('s');
        $qb->where('s.owner_id = :owner_id')
            ->setParameter('owner_id', $ownerId);

        return $qb->getQuery()->getResult();
    }

    public function findRiaSubclasses($riaId)
    {
        return $this->findByOwnerId($riaId);
    }

    public function findDefaultsByModelId($modelId)
    {
        $subclasses = $this->createQueryBuilder('s')
            ->leftJoin('s.assetClass', 'ac')
            ->where('ac.model_id = :model_id')
            ->andWhere('s.owner_id IS NULL AND s.source_id IS NULL')
            ->setParameter('model_id', $modelId)
            ->getQuery()
            ->getResult()
        ;

        return $subclasses;
    }

    public function findDefaultsByAssetClass($assetClassId)
    {
        $subclasses = $this->createQueryBuilder('s')
            ->leftJoin('s.assetClass', 'ac')
            ->where('ac.id = :asset_class_id')
            ->andWhere('s.owner_id IS NULL AND s.source_id IS NULL')
            ->setParameter('asset_class_id', $assetClassId)
            ->getQuery()
            ->getResult()
        ;

        return $subclasses;
    }

    /**
     * Save Client Subclasses
     * This method is needed for save a snapshot of RIA subclasses.
     *
     * @param User $client
     * @param User $ria
     *
     * @throws EntityNotFoundException
     */
    public function saveClientSubclasses(User $client, User $ria)
    {
        $em = $this->getEntityManager();

        $selectedModel = $ria->getRiaCompanyInformation()->getPortfolioModel();

        //$riaSubClasses = $this->findDefaultsByModelId($selectedModel);
        $riaSubClasses = $this->findRiaSubclasses($ria->getId());
        if (!$riaSubClasses) {
            $riaSubClasses = $this->findDefaultsByModelId($selectedModel);
        }

        // When subclasses for RIA are defined in system ?
        if (!$riaSubClasses) {
            throw new EntityNotFoundException("Ria doesn't have subclasses, logic error please check!");
        }

        foreach ($riaSubClasses as $riaSubClass) {
            $clientSubClass = new Subclass();
            $clientSubClass->setAssetClass($riaSubClass->getAssetClass());
            $clientSubClass->setAccountType($riaSubClass->getAccountType());
            $clientSubClass->setOwner($client);
            $clientSubClass->setSource($riaSubClass);
            $clientSubClass->setName($riaSubClass->getName());
            $clientSubClass->setExpectedPerformance($riaSubClass->getExpectedPerformance());

            /** @var Subclass $riaSubClass */
            foreach ($riaSubClass->getSecurityAssignments() as $securityAssignment) {
                $newSecurityAssignment = new SecurityAssignment();
                $newSecurityAssignment->setIsPreferred($securityAssignment->getIsPreferred());
                $newSecurityAssignment->setModel($clientSubClass->getAssetClass()->getModel());
                $newSecurityAssignment->setMuniSubstitution($securityAssignment->getMuniSubstitution());
                //$newSecurityAssignment->setRia($ria); Deprecated
                $newSecurityAssignment->setSecurity($securityAssignment->getSecurity());
                $newSecurityAssignment->setSecurityTransaction($securityAssignment->getSecurityTransaction());
                $newSecurityAssignment->setSubclass($clientSubClass);

                $em->persist($newSecurityAssignment);
            }

            $em->persist($clientSubClass);
        }

        $em->flush();
    }

    public function findByOwnerIdAndAccountTypeId($ownerId, $accountTypeId)
    {
        $subclasses = $this->createQueryBuilder('s')
            ->andWhere('s.owner_id = :owner_id')
            ->andWhere('s.account_type_id = :account_type_id')
            ->setParameter('owner_id', $ownerId)
            ->setParameter('account_type_id', $accountTypeId)
            ->getQuery()
            ->getResult()
        ;

        return $subclasses;
    }

    public function getAvailableSubclassesQuery($assetClassId, User $owner)
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.asset_class_id = :assetClassId')
            ->andWhere("s.name NOT IN ('Intermediate Muni', 'Short Muni')")
            ->setParameters(['assetClassId' => $assetClassId])
            ->orderBy('s.id', 'ASC');

        if ($owner->hasRole('ROLE_RIA') || $owner->hasRole('ROLE_CLIENT')) {
            $qb->leftJoin('s.securityAssignments', 'sec')
                ->andWhere('sec.model_id IS NOT NULL')
                ->andWhere('s.owner_id = :owner_id')
                ->setParameter('owner_id', $owner->getId());
        } else {
            $qb->andWhere('s.owner_id IS NULL AND s.source_id IS NULL');
        }

        return $qb;
    }

    public function findByNameAndModelId($name, $modelId)
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.assetClass', 'ac')
            ->where('s.name = :name')
            ->andWhere('ac.model_id = :model_id')
            ->setParameters([
                'name' => $name,
                'model_id' => $modelId,
            ]);

        return $qb->getQuery()->getResult();
    }
}
