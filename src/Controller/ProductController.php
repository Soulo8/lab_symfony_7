<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ProductSearch;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Form\ProductSearchType;
use App\Form\ProductType;
use App\Service\ProductImageManager;
use App\Service\ProductSearchManager;
use App\Service\TagManager;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use SlopeIt\BreadcrumbBundle\Attribute\Breadcrumb;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Handler\DownloadHandler;

#[Route('/product')]
#[Breadcrumb([
    'label' => 'home',
    'route' => 'app_home',
])]
final class ProductController extends AbstractController
{
    private const int LIMIT_PER_PAGE = 4;

    #[Route('', name: 'app_product_index', methods: ['GET'])]
    #[Breadcrumb([
        ['label' => 'product.list'],
    ])]
    public function index(
        EntityManagerInterface $entityManager,
        PaginatorInterface $paginator,
        ProductSearchManager $productSearchManager,
        Request $request,
        TagManager $tagManager,
        TranslatorInterface $translator,
    ): Response {
        $this->addFlash('info', $translator->trans('info.product.index'));

        $qb = $entityManager->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p');
        $query = $productSearchManager
            ->addFilters($qb, $request)
            ->getQuery();

        $pagination = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            self::LIMIT_PER_PAGE,
            [
                'defaultSortFieldName' => 'p.createdAt',
                'defaultSortDirection' => 'desc',
            ]
        );

        $formSearch = $this->createForm(
            ProductSearchType::class,
            new ProductSearch()
        );
        $formSearch->handleRequest($request);

        return $this->render('product/index.html.twig', [
            'pagination' => $pagination,
            'formSearch' => $formSearch,
            'subTags' => $tagManager->getSubTagsGroupByTag(),
        ]);
    }

    #[Route('/new', name: 'app_product_new', methods: ['GET', 'POST'])]
    #[Breadcrumb([
        ['label' => 'product.list', 'route' => 'app_product_index'],
        ['label' => 'product.new'],
    ])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        ValidatorInterface $validator,
    ): Response {
        $this->addFlash('info', $translator->trans('info.product.new'));

        $product = new Product();

        $form = $this->createForm(
            ProductType::class,
            $product,
            ['validation_groups' => ['create']]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $images = $request->files->get('product')['newImages'];
            foreach ($images as $image) {
                $productImage = new ProductImage();
                $productImage->setImageFile($image);

                $errors = $validator->validate($productImage);

                if (count($errors) > 0) {
                    $this->addFlash(
                        'error',
                        $translator->trans('one_of_the_files_is_not_an_image')
                    );

                    return $this->render('product/new.html.twig', [
                        'product' => $product,
                        'form' => $form,
                    ], new Response(null, 422));
                }

                $product->addImage($productImage);
            }

            if (0 === $product->getImages()->count()) {
                $this->addFlash(
                    'error',
                    $translator->trans('you_have_not_added_an_image')
                );

                return $this->render('product/new.html.twig', [
                    'product' => $product,
                    'form' => $form,
                ], new Response(null, 422));
            }

            $entityManager->persist($product);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('record.added'));

            return $this->redirectToRoute(
                'app_product_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('product/new.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_product_edit', methods: ['GET', 'PUT'])]
    #[Breadcrumb([
        ['label' => 'product.list', 'route' => 'app_product_index'],
        ['label' => 'product.edit'],
    ])]
    public function edit(
        Request $request,
        Product $product,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        ValidatorInterface $validator,
        ProductImageManager $productImageManager,
    ): Response {
        $this->addFlash('info', $translator->trans('info.product.edit'));

        $originalImages = new ArrayCollection();
        foreach ($product->getImages() as $image) {
            $originalImages->add($image);
        }

        $productImageManager->updatePosition($request, $product);

        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            foreach ($originalImages as $image) {
                if (false === $product->getImages()->contains($image)) {
                    $entityManager->remove($image);
                }
            }

            if ($form->isValid()) {
                $images = $request->files->get('product')['newImages'];
                foreach ($images as $image) {
                    $productImage = new ProductImage();
                    $productImage->setImageFile($image);

                    $errors = $validator->validate($productImage);

                    if (count($errors) > 0) {
                        $this->addFlash(
                            'error',
                            $translator->trans(
                                'one_of_the_files_is_not_an_image'
                            )
                        );

                        return $this->render('product/edit.html.twig', [
                            'product' => $product,
                            'form' => $form,
                            'images' => $productImageManager->getImagesData(
                                $product
                            ),
                        ], new Response(null, 422));
                    }

                    $product->addImage($productImage);
                }

                if (0 === $product->getImages()->count()) {
                    $this->addFlash(
                        'error',
                        $translator->trans('you_have_not_added_an_image')
                    );

                    return $this->render('product/edit.html.twig', [
                        'product' => $product,
                        'form' => $form,
                        'images' => $productImageManager->getImagesData(
                            $product
                        ),
                    ], new Response(null, 422));
                }

                $entityManager->flush();

                $this->addFlash(
                    'success',
                    $translator->trans('record.modified')
                );

                return $this->redirectToRoute(
                    'app_product_index',
                    [],
                    Response::HTTP_SEE_OTHER
                );
            }
        }

        return $this->render('product/edit.html.twig', [
            'product' => $product,
            'form' => $form,
            'images' => $productImageManager->getImagesData($product),
        ]);
    }

    #[Route('/{id}', name: 'app_product_delete', methods: ['DELETE'])]
    public function delete(
        Request $request,
        Product $product,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete'.$product->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $entityManager->remove($product);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('record.deleted'));
        }

        return $this->redirectToRoute(
            'app_product_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route(
        '/download-image/{id}',
        name: 'app_product_image',
        methods: ['GET']
    )]
    public function downloadImage(
        ProductImage $image,
        DownloadHandler $downloadHandler,
    ): Response {
        return $downloadHandler->downloadObject(
            $image,
            $fileField = 'imageFile'
        );
    }
}
