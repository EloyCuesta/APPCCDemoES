<?php
declare(strict_types=1);
namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\{QueryCollectionExtensionInterface, QueryItemExtensionInterface};
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\{AccionCorrectiva, ConfiguracionEntidadFiscal, EntidadFiscal, Establecimiento, Evidencia, HistorialIncidencia, PlantillaAPPCC, Usuario};
use App\Security\{CurrentEstablecimientoContext, TenantAuthorization};
use Doctrine\ORM\QueryBuilder;

/** Aplica el ámbito en SQL antes de paginar o resolver elementos/IRI. */
final readonly class TenantExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private CurrentEstablecimientoContext $context, private TenantAuthorization $authorization) {}
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void { $this->apply($queryBuilder, $queryNameGenerator, $resourceClass); }
    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void { $this->apply($queryBuilder, $queryNameGenerator, $resourceClass); }

    private function apply(QueryBuilder $qb, QueryNameGeneratorInterface $names, string $class): void
    {
        if (!$this->context->isApiRequest()) { return; }
        $this->authorization->assertReadClass($class);
        if ($class === PlantillaAPPCC::class) { return; }
        $local = $this->context->establecimiento();
        $root = $qb->getRootAliases()[0];
        $param = $names->generateParameterName('tenant');
        $value = $local->getId();
        if ($class === Establecimiento::class) { $condition = "$root.id = :$param"; }
        elseif ($class === EntidadFiscal::class) { $condition = "$root.id = :$param"; $value = $local->getEntidadFiscal()->getId(); }
        elseif ($class === ConfiguracionEntidadFiscal::class) { $condition = "$root.entidadFiscal = :$param"; $value = $local->getEntidadFiscal()->getId(); }
        elseif ($class === Usuario::class) {
            $join = $names->generateJoinAlias('membresia');
            $qb->innerJoin("$root.usuariosEstablecimiento", $join);
            $condition = "$join.establecimiento = :$param AND $join.activo = true";
        } elseif (in_array($class, [AccionCorrectiva::class, HistorialIncidencia::class], true)) {
            $join = $names->generateJoinAlias('incidencia');
            $qb->innerJoin("$root.incidencia", $join);
            $condition = "$join.establecimiento = :$param";
        } elseif ($class === Evidencia::class) {
            $r = $names->generateJoinAlias('registro'); $i = $names->generateJoinAlias('incidencia');
            $qb->leftJoin("$root.registro", $r)->leftJoin("$root.incidencia", $i);
            $condition = "($r.establecimiento = :$param OR $i.establecimiento = :$param)";
        } else {
            $meta = $qb->getEntityManager()->getClassMetadata($class);
            $condition = $meta->hasAssociation('establecimiento') ? "$root.establecimiento = :$param" : '1 = 0';
        }
        $qb->andWhere($condition);
        if (str_contains($condition, ':'.$param)) { $qb->setParameter($param, $value); }
    }
}
