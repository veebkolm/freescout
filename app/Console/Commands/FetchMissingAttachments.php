<?php

namespace App\Console\Commands;

use App\Attachment;
use App\Mailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FetchMissingAttachments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:fetch-missing-attachments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch missing s3 attachments';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(): void
    {
        $attachments = Attachment::whereNull('file_dir')->get();
        foreach ($attachments as $attachment) {
            $thread = $attachment->thread;

            $to = json_decode($thread->to)[0] ?? null;
            if ($to && $mailbox = Mailbox::where('email', $to)->first()) {
                $client = \MailHelper::getMailboxClient($mailbox);
                $client->connect();
                $imap_folders = $client->getFolders();
                $inbox = $imap_folders[0];
                $messages = $inbox->query()
                    ->from($thread->from)
                    ->get();
                foreach ($messages as $message) {
                    foreach ($message->attachments as $messageAttachment) {
                        if ($attachment->file_name === $messageAttachment->name) {
                            $file_info = Attachment::saveFileToDisk($messageAttachment, $messageAttachment->name, $messageAttachment->content, null);
                            $attachment->file_dir = $file_info['file_dir'];
                            $attachment->size = Storage::disk('s3')->size($file_info['file_path']);
                            $attachment->save();
                            $thread->has_attachments = true;
                            $thread->save();
                            $this->info('Processed attachment ' . $attachment->id . ' on thread ' . $thread->id);
                        }
                    }
                }
            } else {
                $this->warn('Cannot process attachment ' . $attachment->id);
            }
        }
    }
}
