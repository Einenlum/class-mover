<?php

namespace Phpactor\ClassMover\Adapter\WorseTolerant;

use Phpactor\ClassMover\Domain\MemberFinder;
use Phpactor\ClassMover\Domain\Reference\MemberReferences;
use Phpactor\ClassMover\Domain\SourceCode;
use Phpactor\ClassMover\Domain\Model\ClassMemberQuery;
use Phpactor\WorseReflection\Reflector;
use Phpactor\WorseReflection\Core\SourceCodeLocator\StringSourceLocator;
use Phpactor\WorseReflection\Core\SourceCode as WorseSourceCode;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Phpactor\ClassMover\Domain\Reference\MemberReference;
use Phpactor\ClassMover\Domain\Reference\Position;
use Phpactor\WorseReflection\Core\Offset;
use Phpactor\ClassMover\Domain\Model\Class_;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Phpactor\WorseReflection\Core\ClassName;
use Phpactor\ClassMover\Domain\Name\MemberName;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Phpactor\WorseReflection\Core\Type;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Phpactor\WorseReflection\Core\Reflection\AbstractReflectionClass;
use Phpactor\WorseReflection\Core\Reflection\ReflectionClass;
use Microsoft\PhpParser\Node\PropertyDeclaration;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\DelimitedList\ConstElementList;
use Microsoft\PhpParser\Node\ClassConstDeclaration;
use Microsoft\PhpParser\Node\ConstElement;

class WorseTolerantMemberFinder implements MemberFinder
{
    /**
     * @var Reflector
     */
    private $reflector;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Reflector $reflector = null, Parser $parser = null, LoggerInterface $logger = null)
    {
        $this->reflector = $reflector ?: Reflector::create(new StringSourceLocator(WorseSourceCode::fromString('')));
        $this->parser = $parser ?: new Parser();
        $this->logger = $logger ?: new NullLogger();
    }

    public function findMembers(SourceCode $source, ClassMemberQuery $query): MemberReferences
    {
        $rootNode = $this->parser->parseSourceFile((string) $source);
        $memberNodes = $this->collectMemberReferences($rootNode, $query);

        $queryClassReflection = null;
        // TODO: Factor this to a method
        if ($query->hasClass()) {
            $queryClassReflection = $this->resolveBaseReflectionClass($query);
        }

        $references = [];
        foreach ($memberNodes as $memberNode) {
            if ($memberNode instanceof ScopedPropertyAccessExpression && $reference = $this->getScopedPropertyAccessReference($query, $memberNode)) {
                $references[] = $reference;
                continue;
            }

            if ($memberNode instanceof MemberAccessExpression && $reference = $this->getMemberAccessReference($query, $memberNode)) {
                $references[] = $reference;
                continue;
            }

            if ($memberNode instanceof MethodDeclaration && $reference = $this->getMemberDeclarationReference($queryClassReflection, $memberNode)) {
                $references[] = $reference;
                continue;
            }

            // properties ...
            if ($memberNode instanceof Variable && $reference = $this->getMemberDeclarationReference($queryClassReflection, $memberNode)) {
                $references[] = $reference;
                continue;
            }

            if ($memberNode instanceof ConstElement && $reference = $this->getMemberDeclarationReference($queryClassReflection, $memberNode)) {
                $references[] = $reference;
                continue;
            }
        }

        return MemberReferences::fromMemberReferences($references);
    }

    /**
     * Collect all nodes which reference the method NAME.
     * We will check if they belong to the requested class later.
     */
    private function collectMemberReferences(Node $node, ClassMemberQuery $query): array
    {
        $memberNodes = [];
        $memberName = null;

        if (false === $query->hasType() || $query->type() === ClassMemberQuery::TYPE_METHOD) {
            if ($node instanceof MethodDeclaration) {
                $memberName = $node->name->getText($node->getFileContents());

                if ($query->matchesMemberName($memberName)) {
                    $memberNodes[] = $node;
                }
            }

            if ($this->isMethodAccess($node)) {
                $memberName = $node->callableExpression->memberName->getText($node->getFileContents());

                if ($query->matchesMemberName($memberName)) {
                    $memberNodes[] = $node->callableExpression;
                }
            }
        }

        if (false === $query->hasType() || $query->type() === ClassMemberQuery::TYPE_PROPERTY) {
            /** @var PropertyDeclaration $node */
            if ($node instanceof PropertyDeclaration) {
                if ($node->propertyElements) {
                    foreach ($node->propertyElements->getChildNodes() as $propertyElement) {
                        $memberName = $propertyElement->name->getText($propertyElement->getFileContents());
                        if ($query->matchesMemberName($memberName)) {
                            $memberNodes[] = $propertyElement;
                        }
                    }
                }
            }

            // property access - only if it is not part of a call() expression
            if ($node instanceof MemberAccessExpression && false === $node->parent instanceof CallExpression) {
                $memberName = $node->memberName->getText($node->getFileContents());
                if ($query->matchesMemberName($memberName)) {
                    $memberNodes[] = $node;
                }
            }
        }

        if (false === $query->hasType() || $query->type() === ClassMemberQuery::TYPE_CONSTANT) {
            if ($node instanceof ClassConstDeclaration) {
                if ($node->constElements) {
                    foreach ($node->constElements->getChildNodes() as $constElement) {
                        $memberName = $constElement->name->getText($constElement->getFileContents());
                        if ($query->matchesMemberName($memberName)) {
                            $memberNodes[] = $constElement;
                        }
                    }
                }
            }

            if ($node instanceof ScopedPropertyAccessExpression) {
                $memberName = $node->memberName->getText($node->getFileContents());
                if ($query->matchesMemberName($memberName)) {
                    $memberNodes[] = $node;
                }
            }
        }

        foreach ($node->getChildNodes() as $childNode) {
            $memberNodes = array_merge($memberNodes, $this->collectMemberReferences($childNode, $query));
        }

        return $memberNodes;
    }

    private function isMethodAccess(Node $node)
    {
        if (false === $node instanceof CallExpression) {
            return false;
        }

        if (null === $node->callableExpression) {
            return false;
        }

        return 
            $node->callableExpression instanceof MemberAccessExpression || 
            $node->callableExpression instanceof ScopedPropertyAccessExpression;
    }

    private function getMemberDeclarationReference(AbstractReflectionClass $queryClass = null, Node $memberNode)
    {
        // we don't handle Variable calls yet.
        if (false === $memberNode->name instanceof Token) {
            $this->logger->warning('Do not know how to infer method name from variable');
            return;
        }

        $reference = MemberReference::fromMemberNameAndPosition(
            MemberName::fromString((string) $memberNode->name->getText($memberNode->getFileContents())),
            Position::fromStartAndEnd(
                $memberNode->name->start,
                $memberNode->name->start + $memberNode->name->length - 1
            )
        );

        $classNode = $memberNode->getFirstAncestor(ClassDeclaration::class, InterfaceDeclaration::class, TraitDeclaration::class);

        // if no class node found, then this is not valid, don't know how to reproduce this, probably
        // not a possible scenario with the parser.
        if (null === $classNode) {
            return;
        }

        $className = ClassName::fromString($classNode->getNamespacedName());
        $reference = $reference->withClass(Class_::fromString($className));

        if (null === $queryClass) {
            return $reference;
        }

        if (null === $reflectionClass = $this->reflectClass($className)) {
            $this->logger->warning(sprintf('Could not find class "%s" for method declaration, ignoring it', (string) $className));
            return;
        }

        // if the references class is not an instance of the requested class, or the requested class is not
        // an instance of the referenced class then ignore it.
        if (false === $reflectionClass->isTrait() && false === $reflectionClass->isInstanceOf($queryClass->name())) {
            return;
        }

        return $reference;
    }

    /**
     * Get static method call.
     * TODO: This does not support overridden static methods.
     */
    private function getScopedPropertyAccessReference(ClassMemberQuery $query, ScopedPropertyAccessExpression $memberNode)
    {
        $className = $memberNode->scopeResolutionQualifier->getResolvedName();

        if ($query->hasClass() && $className != (string) $query->class()) {
            return;
        }

        return MemberReference::fromMemberNamePositionAndClass(
            MemberName::fromString((string) $memberNode->memberName->getText($memberNode->getFileContents())),
            Position::fromStartAndEnd(
                $memberNode->memberName->start,
                $memberNode->memberName->start + $memberNode->memberName->length
            ),
            Class_::fromString($className)
        );
    }

    private function getMemberAccessReference(ClassMemberQuery $query, MemberAccessExpression $memberNode)
    {
        if (false === $memberNode->memberName instanceof Token) {
            $this->logger->warning('Do not know how to infer method name from variable');
            return;
        }

        $reference = MemberReference::fromMemberNameAndPosition(
            MemberName::fromString((string) $memberNode->memberName->getText($memberNode->getFileContents())),
            Position::fromStartAndEnd(
                $memberNode->memberName->start,
                $memberNode->memberName->start + $memberNode->memberName->length
            )
        );

        $offset = $this->reflector->reflectOffset(
            WorseSourceCode::fromString($memberNode->getFileContents()),
            Offset::fromInt($memberNode->dereferencableExpression->getEndPosition())
        );

        $type = $offset->symbolInformation()->type();

        if ($query->hasMember() && Type::unknown() == $type) {
            return $reference;
        }

        if (false === $type->isClass()) {
            return;
        }


        if (false === $query->hasClass()) {
            $reference = $reference->withClass(Class_::fromString((string) $type->className()));
            return $reference;
        }

        if (null === $reflectionClass = $this->reflectClass($type->className())) {
            $this->logger->warning(sprintf('Could not find class "%s", logging as risky', (string) $type->className()));
            return $reference;
        }
        if (false === $reflectionClass->isInstanceOf(ClassName::fromString((string) $query->class()))) {
            // is not the correct class
            return;
        }

        return $reference->withClass(Class_::fromString((string) $type->className()));
    }

    /**
     * @return ReflectionClass
     */
    private function reflectClass(ClassName $className)
    {
        try {
            return $this->reflector->reflectClassLike($className);
        } catch (NotFound $e) {
            return null;
        }
    }

    /**
     * @return ReflectionClass
     */
    private function resolveBaseReflectionClass(ClassMemberQuery $query)
    {
        $queryClassReflection = $this->reflectClass(ClassName::fromString((string) $query->class()));
        if (null === $queryClassReflection) {
            return $queryClassReflection;
        }

        $methods = $queryClassReflection->methods();

        if (false === $query->hasMember()) {
            return $queryClassReflection;
        }

        if (false === $methods->has($query->memberName())) {
            return $queryClassReflection;
        }

        if (false === $queryClassReflection->isClass()) {
            return $queryClassReflection;
        }

        // TODO: Support the case where interfaces both implement the same method
        foreach ($queryClassReflection->interfaces() as $interfaceReflection) {
            if ($interfaceReflection->methods()->has($query->memberName())) {
                $queryClassReflection = $interfaceReflection;
                break;
            }
        }

        return $queryClassReflection;
    }
}
