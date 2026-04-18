<?php

namespace App\Controller;

use App\Entity\FeedbackSubmission;
use App\Repository\UserProfileRepository;
use App\Service\UserTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

class FeedbackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly UserTokenService $userTokenService,
        private readonly MailerInterface $mailer,
        private readonly string $mailerFrom,
    ) {}

    #[Route('/feedback', name: 'feedback', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $myProfile = $this->getProfile($request);

        return $this->render('feedback/index.html.twig', [
            'my_profile' => $myProfile,
            'success'    => $request->query->getBoolean('success'),
        ]);
    }

    #[Route('/feedback', name: 'feedback_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        // Honeypot
        if ($request->request->get('website') !== '') {
            return $this->redirectToRoute('feedback');
        }

        $name    = trim($request->request->getString('name'));
        $email   = trim($request->request->getString('email')) ?: null;
        $type    = $request->request->getString('type');
        $message = trim($request->request->getString('message'));

        $validTypes = ['bug', 'feedback', 'feature'];
        if ($name === '' || $message === '' || !in_array($type, $validTypes, true)) {
            return $this->redirectToRoute('feedback');
        }

        $submission = (new FeedbackSubmission())
            ->setName(substr($name, 0, 100))
            ->setEmail($email ? substr($email, 0, 150) : null)
            ->setType($type)
            ->setMessage(substr($message, 0, 5000))
            ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($submission);
        $this->em->flush();

        $typeLabels = ['bug' => 'Bug Report', 'feedback' => 'Feedback', 'feature' => 'Feature Request'];
        $typeLabel  = $typeLabels[$type] ?? $type;

        try {
            $emailMessage = (new Email())
                ->from($this->mailerFrom)
                ->to($this->mailerFrom)
                ->replyTo($email ?? $this->mailerFrom)
                ->subject("[{$typeLabel}] {$name} via steamgametracker.com")
                ->text(implode("\n\n", [
                    "Type: {$typeLabel}",
                    "Name: {$name}",
                    "Email: " . ($email ?? 'not provided'),
                    "Message:\n{$message}",
                ]));
            $this->mailer->send($emailMessage);
        } catch (\Exception) {
            // Submission is saved to DB even if email fails
        }

        return $this->redirectToRoute('feedback', ['success' => 1]);
    }

    #[Route('/contributors', name: 'contributors', methods: ['GET'])]
    public function contributors(Request $request): Response
    {
        $myProfile = $this->getProfile($request);

        return $this->render('feedback/contributors.html.twig', [
            'my_profile' => $myProfile,
        ]);
    }

    private function getProfile(Request $request): mixed
    {
        $token = $this->userTokenService->getToken($request);
        return $token ? $this->userProfileRepository->findOneBy(['userToken' => $token]) : null;
    }
}
