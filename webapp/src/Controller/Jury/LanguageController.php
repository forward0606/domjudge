<?php declare(strict_types=1);

namespace App\Controller\Jury;

use App\Controller\BaseController;
use App\Entity\Judging;
use App\Entity\Language;
use App\Entity\Submission;
use App\Form\Type\LanguageType;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\SubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/jury/languages")
 * @IsGranted("ROLE_JURY")
 */
class LanguageController extends BaseController
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly DOMJudgeService $dj,
        protected readonly ConfigurationService $config,
        protected readonly KernelInterface $kernel,
        protected readonly EventLogService $eventLogService
    ) {}

    /**
     * @Route("", name="jury_languages")
     */
    public function indexAction(): Response
    {
        $em = $this->em;
        /** @var Language[] $languages */
        $languages    = $em->createQueryBuilder()
            ->select('lang')
            ->from(Language::class, 'lang')
            ->orderBy('lang.name', 'ASC')
            ->getQuery()->getResult();
        $table_fields = [
            'langid' => ['title' => 'ID/ext', 'sort' => true],
            'name' => ['title' => 'name', 'sort' => true, 'default_sort' => true],
            'entrypoint' => ['title' => 'entry point', 'sort' => true],
            'allowjudge' => ['title' => 'allow judge', 'sort' => true],
            'timefactor' => ['title' => 'timefactor', 'sort' => true],
            'extensions' => ['title' => 'extensions', 'sort' => true],
            'executable' => ['title' => 'executable', 'sort' => true],
        ];

        // Insert external ID field when configured to use it.
        if ($externalIdField = $this->eventLogService->externalIdFieldForEntity(Language::class)) {
            $table_fields = array_slice($table_fields, 0, 1, true) +
                [$externalIdField => ['title' => 'external ID', 'sort' => true]] +
                array_slice($table_fields, 1, null, true);
        }

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $enabled_languages  = [];
        $disabled_languages  = [];
        foreach ($languages as $lang) {
            $langdata    = [];
            $langactions = [];
            // Get whatever fields we can from the language object itself.
            foreach ($table_fields as $k => $v) {
                if ($propertyAccessor->isReadable($lang, $k)) {
                    $langdata[$k] = ['value' => $propertyAccessor->getValue($lang, $k)];
                }
            }

            if ($this->isGranted('ROLE_ADMIN')) {
                $langactions[] = [
                    'icon' => 'edit',
                    'title' => 'edit this language',
                    'link' => $this->generateUrl('jury_language_edit', [
                        'langId' => $lang->getLangid()
                    ])
                ];
                $langactions[] = [
                    'icon' => 'trash-alt',
                    'title' => 'delete this language',
                    'link' => $this->generateUrl('jury_language_delete', [
                        'langId' => $lang->getLangid(),
                    ]),
                    'ajaxModal' => true,
                ];
            }

            $executable = $lang->getCompileExecutable();

            // Merge in the rest of the data.
            $langdata = array_merge($langdata, [
                'entrypoint' => ['value' => $lang->getRequireEntryPoint() ? 'yes' : 'no'],
                'extensions' => ['value' => implode(', ', $lang->getExtensions())],
                'allowjudge' => $lang->getAllowJudge() ?
                    ['value' => 'yes'] : ['value' => 'no', 'cssclass'=>'text-danger font-weight-bold'],
                'executable' => [
                    'value' => $executable === null ? '-' : $executable->getShortDescription(),
                    'link' => $executable === null ? null : $this->generateUrl('jury_executable', [
                        'execId' => $executable->getExecid()
                        ]),
                    'showlink' => true,
                    ],
            ]);

            if ($lang->getAllowSubmit()) {
                $enabled_languages[] = [
                    'data' => $langdata,
                    'actions' => $langactions,
                    'link' => $this->generateUrl('jury_language', ['langId' => $lang->getLangid()]),
                    'cssclass' => '',
                ];
            } else {
                $disabled_languages[] = [
                    'data' => $langdata,
                    'actions' => $langactions,
                    'link' => $this->generateUrl('jury_language', ['langId' => $lang->getLangid()]),
                    'cssclass' => 'disabled',
                ];
            }
        }
        return $this->render('jury/languages.html.twig', [
            'enabled_languages' => $enabled_languages,
            'disabled_languages' => $disabled_languages,
            'table_fields' => $table_fields,
            'num_actions' => $this->isGranted('ROLE_ADMIN') ? 2 : 0,
        ]);
    }

    // Note that the add action appears before the view action to make sure
    // /add is not seen as a language.
    /**
     * @Route("/add", name="jury_language_add")
     * @IsGranted("ROLE_ADMIN")
     */
    public function addAction(Request $request): Response
    {
        $language = new Language();

        $form = $this->createForm(LanguageType::class, $language);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Normalize extensions
            if ($language->getExtensions()) {
                $language->setExtensions(array_values($language->getExtensions()));
            }
            $this->em->persist($language);
            $this->saveEntity($this->em, $this->eventLogService, $this->dj, $language,
                              $language->getLangid(), true);
            return $this->redirect($this->generateUrl(
                'jury_language',
                ['langId' => $language->getLangid()]
            ));
        }

        return $this->render('jury/language_add.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{langId}", name="jury_language")
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function viewAction(Request $request, SubmissionService $submissionService, string $langId): Response
    {
        /** @var Language $language */
        $language = $this->em->getRepository(Language::class)->find($langId);
        if (!$language) {
            throw new NotFoundHttpException(sprintf('Language with ID %s not found', $langId));
        }

        $restrictions = ['langid' => $language->getLangid()];
        /** @var Submission[] $submissions */
        [$submissions, $submissionCounts] = $submissionService->getSubmissionList(
            $this->dj->getCurrentContests(),
            $restrictions
        );

        $data = [
            'language' => $language,
            'submissions' => $submissions,
            'submissionCounts' => $submissionCounts,
            'showContest' => count($this->dj->getCurrentContests()) > 1,
            'showExternalResult' => $this->config->get('data_source') ==
                DOMJudgeService::DATA_SOURCE_CONFIGURATION_AND_LIVE_EXTERNAL,
            'refresh' => [
                'after' => 15,
                'url' => $this->generateUrl('jury_language', ['langId' => $language->getLangid()]),
                'ajax' => true,
            ],
        ];

        // For ajax requests, only return the submission list partial.
        if ($request->isXmlHttpRequest()) {
            $data['showTestcases'] = false;
            return $this->render('jury/partials/submission_list.html.twig', $data);
        }

        return $this->render('jury/language.html.twig', $data);
    }

    /**
     * @Route("/{langId}/toggle-submit", name="jury_language_toggle_submit")
     */
    public function toggleSubmitAction(Request $request, string $langId): Response
    {
        /** @var Language $language */
        $language = $this->em->getRepository(Language::class)->find($langId);
        if (!$language) {
            throw new NotFoundHttpException(sprintf('Language with ID %s not found', $langId));
        }

        $language->setAllowSubmit($request->request->getBoolean('allow_submit'));
        $this->em->flush();

        $this->dj->auditlog('language', $langId, 'set allow submit',
                                         $request->request->getBoolean('allow_submit') ? 'yes' : 'no');
        return $this->redirectToRoute('jury_language', ['langId' => $langId]);
    }

    /**
     * @Route("/{langId}/toggle-judge", name="jury_language_toggle_judge")
     */
    public function toggleJudgeAction(Request $request, string $langId): Response
    {
        /** @var Language $language */
        $language = $this->em->getRepository(Language::class)->find($langId);
        if (!$language) {
            throw new NotFoundHttpException(sprintf('Language with ID %s not found', $langId));
        }

        $enabled = $request->request->getBoolean('allow_judge');
        $language->setAllowJudge($enabled);
        $this->em->flush();

        if ($enabled) {
            $this->dj->unblockJudgeTasksForLanguage($langId);
        }

        $this->dj->auditlog('language', $langId, 'set allow judge',
                                         $request->request->getBoolean('allow_judge') ? 'yes' : 'no');
        return $this->redirectToRoute('jury_language', ['langId' => $langId]);
    }

    /**
     * @Route("/{langId}/edit", name="jury_language_edit")
     * @IsGranted("ROLE_ADMIN")
     */
    public function editAction(Request $request, string $langId): Response
    {
        /** @var Language $language */
        $language = $this->em->getRepository(Language::class)->find($langId);
        if (!$language) {
            throw new NotFoundHttpException(sprintf('Language with ID %s not found', $langId));
        }

        $form = $this->createForm(LanguageType::class, $language);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Normalize extensions.
            if ($language->getExtensions()) {
                $language->setExtensions(array_values($language->getExtensions()));
            }
            $this->saveEntity($this->em, $this->eventLogService, $this->dj, $language,
                              $language->getLangid(), false);
            if ($language->getAllowJudge()) {
                $this->dj->unblockJudgeTasksForLanguage($langId);
            }
            return $this->redirect($this->generateUrl(
                'jury_language',
                ['langId' => $language->getLangid()]
            ));
        }

        return $this->render('jury/language_edit.html.twig', [
            'language' => $language,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{langId}/delete", name="jury_language_delete")
     * @IsGranted("ROLE_ADMIN")
     */
    public function deleteAction(Request $request, string $langId): Response
    {
        /** @var Language $language */
        $language = $this->em->getRepository(Language::class)->find($langId);
        if (!$language) {
            throw new NotFoundHttpException(sprintf('Language with ID %s not found', $langId));
        }

        return $this->deleteEntities($request, $this->em, $this->dj, $this->eventLogService, $this->kernel,
                                     [$language], $this->generateUrl('jury_languages')
        );
    }

    /**
     * @Route("/{langId}/request-remaining", name="jury_language_request_remaining")
     */
    public function requestRemainingRunsWholeLanguageAction(string $langId): RedirectResponse
    {
        /** @var Language $language */
        $language = $this->em->getRepository(Language::class)->find($langId);
        if (!$language) {
            throw new NotFoundHttpException(sprintf('Language with ID %s not found', $langId));
        }
        $contestId = $this->dj->getCurrentContest()->getCid();
        $query = $this->em->createQueryBuilder()
                          ->from(Judging::class, 'j')
                          ->select('j')
                          ->join('j.submission', 's')
                          ->join('s.team', 't')
                          ->andWhere('j.valid = true')
                          ->andWhere('j.result != :compiler_error')
                          ->andWhere('s.language = :langId')
                          ->setParameter('compiler_error', 'compiler-error')
                          ->setParameter('langId', $langId);
        if ($contestId > -1) {
            $query->andWhere('s.contest = :contestId')
                  ->setParameter('contestId', $contestId);
        }
        $judgings = $query->getQuery()
                          ->getResult();
        $this->judgeRemaining($judgings);
        return $this->redirect($this->generateUrl('jury_language', ['langId' => $langId]));
    }
}
