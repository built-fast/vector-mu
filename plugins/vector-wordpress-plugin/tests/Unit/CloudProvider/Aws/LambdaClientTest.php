<?php

declare(strict_types=1);

/*
 * This file is part of Ymir WordPress plugin.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 * Modified by Vector (2026). See README.md for a summary of changes.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Plugin\Tests\Unit\CloudProvider\Aws;

use Ymir\Plugin\CloudProvider\Aws\LambdaClient;
use Ymir\Plugin\Tests\Mock\FunctionMockTrait;
use Ymir\Plugin\Tests\Mock\HttpClientMockTrait;
use Ymir\Plugin\Tests\Mock\WPPostMockTrait;
use Ymir\Plugin\Tests\Unit\TestCase;

class LambdaClientTest extends TestCase
{
    use FunctionMockTrait;
    use HttpClientMockTrait;
    use WPPostMockTrait;

    public function testCreateAttachmentMetadata(): void
    {
        $http = $this->getHttpClientMock();
        $http->expects($this->once())
             ->method('request')
             ->with(
                 $this->identicalTo('https://lambda.us-east-1.amazonaws.com/2015-03-31/functions/test-function/invocations?Qualifier=deployed'),
                 $this->identicalTo([
                     'headers' => [
                         'content-type' => 'application/json',
                         'host' => 'lambda.us-east-1.amazonaws.com',
                         'x-amz-content-sha256' => '819ada831ace5a65b254f3f39b303caaeec37f01f178fb444ebd95372f2b4432',
                         'x-amz-date' => '20200515T181004Z',
                         'x-amz-invocation-type' => 'RequestResponse',
                         'authorization' => 'AWS4-HMAC-SHA256 Credential=aws-key/20200515/us-east-1/lambda/aws4_request,SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-invocation-type,Signature=b0b6be867f535a90371d186c913f92adab164a88f2f3c49be7a1b7a171db7a46',
                         'content-length' => 79,
                     ],
                     'method' => 'POST',
                     'timeout' => 300,
                     'body' => '{"php":"bin\/wp vector create-attachment-metadata 4 --url=\'https:\/\/foo.bar\'"}',
                 ])
             )
             ->willReturn([
                 'body' => '{"output": "Success: Created metadata for attachment \"4\""}',
             ]);

        $gmdate = $this->getFunctionMock($this->getNamespace(LambdaClient::class), 'gmdate');
        $gmdate->expects($this->exactly(5))
               ->withConsecutive(
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd')]
               )
               ->willReturnOnConsecutiveCalls('20200515T181004Z', '20200515', '20200515T181004Z', '20200515', '20200515');

        $post = $this->getWPPostMock();
        $post->ID = 4;

        (new LambdaClient($http, 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar'))->createAttachmentMetadata($post);
    }

    public function testCreateAttachmentMetadataWithSpecialCharacters(): void
    {
        $http = $this->getHttpClientMock();
        $http->expects($this->once())
            ->method('request')
            ->with(
                $this->identicalTo('https://lambda.us-east-1.amazonaws.com/2015-03-31/functions/test-function/invocations?Qualifier=deployed'),
                $this->identicalTo([
                    'headers' => [
                        'content-type' => 'application/json',
                        'host' => 'lambda.us-east-1.amazonaws.com',
                        'x-amz-content-sha256' => '819ada831ace5a65b254f3f39b303caaeec37f01f178fb444ebd95372f2b4432',
                        'x-amz-date' => '20200515T181004Z',
                        'x-amz-invocation-type' => 'RequestResponse',
                        'authorization' => 'AWS4-HMAC-SHA256 Credential=aws-key/20200515/us-east-1/lambda/aws4_request,SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-invocation-type,Signature=b0b6be867f535a90371d186c913f92adab164a88f2f3c49be7a1b7a171db7a46',
                        'content-length' => 79,
                    ],
                    'method' => 'POST',
                    'timeout' => 300,
                    'body' => '{"php":"bin\/wp vector create-attachment-metadata 4 --url=\'https:\/\/foo.bar\'"}',
                ])
            )
            ->willReturn([
                'body' => '{"output": "\u001b[32;1mSuccess:\u001b[0m Created metadata for attachment \"4\""}',
            ]);

        $gmdate = $this->getFunctionMock($this->getNamespace(LambdaClient::class), 'gmdate');
        $gmdate->expects($this->exactly(5))
            ->withConsecutive(
                [$this->identicalTo('Ymd\THis\Z')],
                [$this->identicalTo('Ymd')],
                [$this->identicalTo('Ymd\THis\Z')],
                [$this->identicalTo('Ymd')],
                [$this->identicalTo('Ymd')]
            )
            ->willReturnOnConsecutiveCalls('20200515T181004Z', '20200515', '20200515T181004Z', '20200515', '20200515');

        $post = $this->getWPPostMock();
        $post->ID = 4;

        (new LambdaClient($http, 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar'))->createAttachmentMetadata($post);
    }

    public function testCreateCroppedAttachmentImage(): void
    {
        $http = $this->getHttpClientMock();
        $http->expects($this->once())
             ->method('request')
             ->with(
                 $this->identicalTo('https://lambda.us-east-1.amazonaws.com/2015-03-31/functions/test-function/invocations?Qualifier=deployed'),
                 $this->identicalTo([
                     'headers' => [
                         'content-type' => 'application/json',
                         'host' => 'lambda.us-east-1.amazonaws.com',
                         'x-amz-content-sha256' => '6f4f4c6480dac910e86f553c5657bc474205f52b4e2afc1d9eecbd2d9a342705',
                         'x-amz-date' => '20200515T181004Z',
                         'x-amz-invocation-type' => 'RequestResponse',
                         'authorization' => 'AWS4-HMAC-SHA256 Credential=aws-key/20200515/us-east-1/lambda/aws4_request,SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-invocation-type,Signature=983997dcc520bd0bad7044a9b1274a01b478600323ac45e09ab3418fccd19a9a',
                         'content-length' => 118,
                     ],
                     'method' => 'POST',
                     'timeout' => 300,
                     'body' => '{"php":"bin\/wp vector create-cropped-image 4 --width=\'42\' --height=\'24\' --x=\'14\' --y=\'21\' --url=\'https:\/\/foo.bar\'"}',
                 ])
             )
             ->willReturn([
                 'body' => '{"output": "Success: Cropped attachment image successfully created with ID 5"}',
             ]);

        $gmdate = $this->getFunctionMock($this->getNamespace(LambdaClient::class), 'gmdate');
        $gmdate->expects($this->exactly(5))
               ->withConsecutive(
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd')]
               )
               ->willReturnOnConsecutiveCalls('20200515T181004Z', '20200515', '20200515T181004Z', '20200515', '20200515');

        $post = $this->getWPPostMock();
        $post->ID = 4;

        $this->assertSame(5, (new LambdaClient($http, 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar'))->createCroppedAttachmentImage($post, 42, 24, 14, 21));
    }

    public function testCreateCroppedAttachmentImageQuotesContext(): void
    {
        $expected = <<<'CMD'
bin/wp vector create-cropped-image 4 --width='42' --height='24' --x='14' --y='21' --context='x'\''; touch /tmp/pwned; echo '\''' --url='https://foo.bar'
CMD;

        $client = new LambdaClient($this->getCapturingHttpClientMock($payload, 'Success: Created cropped attachment image with ID 5'), 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar');
        $client->createCroppedAttachmentImage(4, 42, 24, 14, 21, "x'; touch /tmp/pwned; echo '");

        $this->assertSame($expected, $payload['php']);
    }

    public function testCreateCroppedAttachmentImageWithSiteIconContext(): void
    {
        $http = $this->getHttpClientMock();
        $http->expects($this->once())
             ->method('request')
             ->with(
                 $this->identicalTo('https://lambda.us-east-1.amazonaws.com/2015-03-31/functions/test-function/invocations?Qualifier=deployed'),
                 $this->identicalTo([
                     'headers' => [
                         'content-type' => 'application/json',
                         'host' => 'lambda.us-east-1.amazonaws.com',
                         'x-amz-content-sha256' => '6531d9e06952e261b5fca0cfe97519221b3c5cdd1936cef8d08f075bff946a2c',
                         'x-amz-date' => '20200515T181004Z',
                         'x-amz-invocation-type' => 'RequestResponse',
                         'authorization' => 'AWS4-HMAC-SHA256 Credential=aws-key/20200515/us-east-1/lambda/aws4_request,SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-invocation-type,Signature=60c7a8f5b59b64d70a340bd405c0438d2e4909aaacd0064cc83be6a6cdf7b40f',
                         'content-length' => 114,
                     ],
                     'method' => 'POST',
                     'timeout' => 300,
                     'body' => '{"php":"bin\/wp vector create-site-icon 4 --width=\'42\' --height=\'24\' --x=\'14\' --y=\'21\' --url=\'https:\/\/foo.bar\'"}',
                 ])
             )
             ->willReturn([
                 'body' => '{"output": "Success: Site icon successfully created with ID 5"}',
             ]);

        $gmdate = $this->getFunctionMock($this->getNamespace(LambdaClient::class), 'gmdate');
        $gmdate->expects($this->exactly(5))
               ->withConsecutive(
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd')]
               )
               ->willReturnOnConsecutiveCalls('20200515T181004Z', '20200515', '20200515T181004Z', '20200515', '20200515');

        $post = $this->getWPPostMock();
        $post->ID = 4;

        $this->assertSame(5, (new LambdaClient($http, 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar'))->createCroppedAttachmentImage($post, 42, 24, 14, 21, 'site-icon'));
    }

    public function testEditAttachmentImage(): void
    {
        $http = $this->getHttpClientMock();
        $http->expects($this->once())
             ->method('request')
             ->with(
                 $this->identicalTo('https://lambda.us-east-1.amazonaws.com/2015-03-31/functions/test-function/invocations?Qualifier=deployed'),
                 $this->identicalTo([
                     'headers' => [
                         'content-type' => 'application/json',
                         'host' => 'lambda.us-east-1.amazonaws.com',
                         'x-amz-content-sha256' => 'ff6ccfe4171211859d3b83a7b0ddd0384d0117d3f4cfc8d14fbe0bb2352d5407',
                         'x-amz-date' => '20200515T181004Z',
                         'x-amz-invocation-type' => 'RequestResponse',
                         'authorization' => 'AWS4-HMAC-SHA256 Credential=aws-key/20200515/us-east-1/lambda/aws4_request,SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-invocation-type,Signature=d639871ce73b0536623bf1a052aafef6f6550bf92cbefbbfbd403dfbc799ba0c',
                         'content-length' => 103,
                     ],
                     'method' => 'POST',
                     'timeout' => 300,
                     'body' => '{"php":"bin\/wp vector edit-attachment-image 4 \'[{\"r\":90}]\' --apply=\'all\' --url=\'https:\/\/foo.bar\'"}',
                 ])
             )
             ->willReturn([
                 'body' => '{"output": "Success: Edited attachment \"4\""}',
             ]);

        $gmdate = $this->getFunctionMock($this->getNamespace(LambdaClient::class), 'gmdate');
        $gmdate->expects($this->exactly(5))
               ->withConsecutive(
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd')]
               )
               ->willReturnOnConsecutiveCalls('20200515T181004Z', '20200515', '20200515T181004Z', '20200515', '20200515');

        $post = $this->getWPPostMock();
        $post->ID = 4;

        (new LambdaClient($http, 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar'))->editAttachmentImage($post, '[{"r":90}]');
    }

    public function testEditAttachmentImageQuotesChanges(): void
    {
        $changes = <<<'JSON'
[{"c":"'; touch /tmp/pwned; echo '"}]
JSON;
        $expected = <<<'CMD'
bin/wp vector edit-attachment-image 4 '[{"c":"'\''; touch /tmp/pwned; echo '\''"}]' --apply='all' --url='https://foo.bar'
CMD;

        $client = new LambdaClient($this->getCapturingHttpClientMock($payload, 'Success: Edited attachment'), 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar');
        $client->editAttachmentImage(4, $changes);

        $this->assertSame($expected, $payload['php']);
    }

    public function testEditAttachmentImageWithInvalidJsonChanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The given image "changes" must be valid JSON');

        $http = $this->getHttpClientMock();
        $http->expects($this->never())->method('request');

        (new LambdaClient($http, 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar'))->editAttachmentImage(4, "'; touch /tmp/pwned; echo '");
    }

    public function testResizeAttachmentImage(): void
    {
        $http = $this->getHttpClientMock();
        $http->expects($this->once())
             ->method('request')
             ->with(
                 $this->identicalTo('https://lambda.us-east-1.amazonaws.com/2015-03-31/functions/test-function/invocations?Qualifier=deployed'),
                 $this->identicalTo([
                     'headers' => [
                         'content-type' => 'application/json',
                         'host' => 'lambda.us-east-1.amazonaws.com',
                         'x-amz-content-sha256' => '695b4d1b62850642688b89fa4c7fdb359a655b77f13b18178ac13c0ddf5e5f30',
                         'x-amz-date' => '20200515T181004Z',
                         'x-amz-invocation-type' => 'RequestResponse',
                         'authorization' => 'AWS4-HMAC-SHA256 Credential=aws-key/20200515/us-east-1/lambda/aws4_request,SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date;x-amz-invocation-type,Signature=dbad6158e722450dc2e2240aa06bfc785bfc32764e50cc2845300c23e95299e3',
                         'content-length' => 103,
                     ],
                     'method' => 'POST',
                     'timeout' => 300,
                     'body' => '{"php":"bin\/wp vector resize-attachment-image 4 --width=\'42\' --height=\'24\' --url=\'https:\/\/foo.bar\'"}',
                 ])
             )
             ->willReturn([
                 'body' => '{"output": "Success: Resized attachment \"4\" to 42x24"}',
             ]);

        $gmdate = $this->getFunctionMock($this->getNamespace(LambdaClient::class), 'gmdate');
        $gmdate->expects($this->exactly(5))
               ->withConsecutive(
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd\THis\Z')],
                   [$this->identicalTo('Ymd')],
                   [$this->identicalTo('Ymd')]
               )
               ->willReturnOnConsecutiveCalls('20200515T181004Z', '20200515', '20200515T181004Z', '20200515', '20200515');

        $post = $this->getWPPostMock();
        $post->ID = 4;

        (new LambdaClient($http, 'test-function', 'aws-key', 'us-east-1', 'aws-secret', 'https://foo.bar'))->resizeAttachmentImage($post, 42, 24);
    }

    /**
     * Get an HTTP client mock that captures the decoded Lambda payload.
     */
    private function getCapturingHttpClientMock(&$payload, string $output): \PHPUnit\Framework\MockObject\MockObject
    {
        $http = $this->getHttpClientMock();
        $http->expects($this->once())
             ->method('request')
             ->willReturnCallback(function (string $url, array $options) use (&$payload, $output) {
                 $payload = json_decode($options['body'], true);

                 return ['body' => json_encode(['output' => $output])];
             });

        return $http;
    }
}
