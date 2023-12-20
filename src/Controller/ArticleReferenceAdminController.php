<?php

namespace App\Controller;

use App\Api\ArticleReferenceUploadApiModel;
use App\Entity\Article;
use App\Entity\ArticleReference;
use App\Service\UploaderHelper;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\File\File as FileObject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ArticleReferenceAdminController extends BaseController
{

    /**
     * @Route("/admin/article/{id}/references", name="admin_article_add_reference", methods={"POST"})
     * @IsGranted("MANAGE", subject="article")
     */
    public function uploadArticleReference(Article $article, Request $request, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager, ValidatorInterface $valitor, SerializerInterface $serializer)
    {
      if ($request->headers->get('Content-Type') === 'application/json') {
        /** @var ArticleReferenceUploadApiModel $uploadApiModel */
        $uploadApiModel = $serializer->deserialize(
          $request->getContent(),
          ArticleReferenceUploadApiModel::class,
          'json'
        );

        $violations = $valitor->validate($uploadApiModel);

        if ($violations->count() > 0) {
          return $this->json($violations, 400);
        }

        $tmpPath = sys_get_temp_dir().'/sf_upload'.uniqid();
        file_put_contents($tmpPath, $uploadApiModel->getDecodedData());

        $uploadedFile = new FileObject($tmpPath);
        $originalName = $uploadApiModel->filename;
      } else {
        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('reference');
        $originalName = $uploadedFile->getClientOriginalName();
      }

      $violations = $valitor->validate(
        $uploadedFile,
        [
          new NotBlank([
            'message' => 'Please select a file to upload'
          ]),
          new File([
            'maxSize' => '5M',
            'mimeTypes' => [
              'image/*',
              'application/pdf',
              'application/msword',
              'application/vnd.ms-excel',
              'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
              'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              'application/vnd.openxmlformats-officedocument.presentationml.presentation',
              'text/plain'
            ]
          ])
        ]
      );

      if ($violations->count() > 0) {
        return $this->json($violations, 400);
      }

      $filename = $uploaderHelper->uploadArticleReference($uploadedFile);

      $articleReference = new ArticleReference($article);
      $articleReference->setFilename($filename);
      $articleReference->setOriginalFilename($originalName ?? $filename);
      $articleReference->setMimeType($uploadedFile->getMimeType() ?? 'aplication/octet-stream');

      if (is_file($uploadedFile->getPathname())) {
        unlink($uploadedFile->getPathname());
      }

      $entityManager->persist($articleReference);
      $entityManager->flush();

      return $this->json(
        $articleReference,
        201, 
        [],
        [
          'groups' => ['main']
        ]
      );
    }

    /**
     * @Route("/admin/article/{id}/references", methods="GET", name="admin_article_list_references")
     * @IsGranted("MANAGE", subject="article")
     */
    public function getArticleReferences(Article $article)
    {
      return $this->json(
        $article->getArticleReferences(),
        200,
        [],
        [
            'groups' => ['main']
        ]
      );
    }

    /**
     * @Route("/admin/article/{id}/references/reorder", methods="POST", name="admin_article_reorder_references")
     * @IsGranted("MANAGE", subject="article")
     */
    public function reorderArticleReferences(Article $article, Request $request, EntityManagerInterface $entityManager)
    {
      $orderedIds = json_decode($request->getContent(), true);

      if ($orderedIds === null) {
          return $this->json(['detail' => 'Invalid body'], 400);
      }

      // from (position)=>(id) to (id)=>(position)
      $orderedIds = array_flip($orderedIds);

      foreach ($article->getArticleReferences() as $reference) {
          $reference->setPosition($orderedIds[$reference->getId()]);
      }

      $entityManager->flush();

      return $this->json(
        $article->getArticleReferences(),
        200,
        [],
        [
            'groups' => ['main']
        ]
      );
    }    

    /**
     * @Route("/admin/article/references/{id}/download", name="admin_article_download_reference", methods={"GET"})
     */
    public function downloadArticleReference(ArticleReference $reference, S3Client $s3Client, string $s3BucketName)
    {
      $article = $reference->getArticle();
      $this->denyAccessUnlessGranted('MANAGE', $article);

      $disposition = HeaderUtils::makeDisposition(
        ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        $reference->getOriginalFilename()
      );

      $command = $s3Client->getCommand('GetObject', [
        'Bucket' => $s3BucketName,
        'Key' => $reference->getFilePath(),
        'ResponseContentType' => $reference->getMimeType(),
        'ResponseContentDisposition' => $disposition,
      ]);
      
      $request = $s3Client->createPresignedRequest($command, '+30 minutes');
      
      return new RedirectResponse((string) $request->getUri());
    }

    /**
     * @Route("/admin/article/references/{id}", name="admin_article_delete_reference", methods={"DELETE"})
     */
    public function deleteArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager)
    {
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        $entityManager->remove($reference);
        $entityManager->flush();

        $uploaderHelper->deleteFile($reference->getFilePath());

        return new Response(null, 204);
    }


    /**
     * @Route("/admin/article/references/{id}", name="admin_article_update_reference", methods={"PUT"})
     */
    public function updateArticleReference(ArticleReference $reference, ValidatorInterface $validator, EntityManagerInterface $entityManager, SerializerInterface $serializer, Request $request)
    {
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        $serializer->deserialize(
          $request->getContent(),
          ArticleReference::class,
          'json',
          [
            'object_to_populate' => $reference,
            'groups' => ['input']
          ]
        );

        $violations = $validator->validate($reference);
        if ($violations->count() > 0) {
            return $this->json($violations, 400);
        }

        $entityManager->flush();

        return $this->json(
          $reference,
          200, 
          [],
          [
            'groups' => ['main']
          ]
        );
    }



}