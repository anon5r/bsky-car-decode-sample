<?php
require_once '../vendor/autoload.php';

$username = $_POST['username'];

$client = new \GuzzleHttp\Client;
try {
    // resolve handle to DID
    $did = resolveHandle($client, $username);
    // detect personal data server
    $pds = detectPDS($client, $did);
    // remove directory if exists
    removeDirectory($did);
    
    // get car file as stream
    $stream = getRepoAsStream($client, $pds, $did);
    $filepath = sprintf('%s.car', tempnam('.', 'repo_'));
    if (file_exists($filepath))
        unlink($filepath);
    // save stream to file
    file_put_contents($filepath, $stream);
    
    // unpack car file by gosky
    $cmd =  sprintf('gosky car unpack %s', $filepath);
    exec($cmd, $output, $return_var);
    // remove car file
    if (file_exists($filepath))
        unlink($filepath);
    
    // output posts
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(outputPosts($did));
    
    // cleanup
    removeDirectory($did);
    
} catch (\Guzzle\Common\Exception\RuntimeException $e) {
    echo $e->getMessage();
}

/**
 * ハンドルを解決してDIDを取得
 * @param \GuzzleHttp\Client $client
 * @param $username
 * @return mixed
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function resolveHandle(\GuzzleHttp\Client $client, $username) {
    $response = $client->request('GET',
        'https://bsky.social/xrpc/com.atproto.identity.resolveHandle',
        ['query' => ['handle' => $username]]
    );
    if ($response->getStatusCode() !== 200)
        throw new \Guzzle\Common\Exception\RuntimeException('Failed to resolve handle');
    
    $data = json_decode($response->getBody());
    
    return $data->did;
}

/**
 * DIDからPDSを検出
 * @param \GuzzleHttp\Client $client
 * @param $did
 * @return null
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function detectPDS(\GuzzleHttp\Client $client, $did) {
    $response = $client->request('GET',
        sprintf('https://plc.directory/%s', $did));
    $data = json_decode($response->getBody());
    foreach ($data->service as $service)
        if ($service->type === 'AtprotoPersonalDataServer')
            return $service->serviceEndpoint;
    return null;
}

/**
 * DAG-CBORでエンコードされたリポジトリを取得
 * @param \GuzzleHttp\Client $client
 * @param $pds
 * @param $did
 * @return string
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function getRepoAsStream(\GuzzleHttp\Client $client, $pds, $did) {
    $response = $client->request('GET',
        sprintf('%s/xrpc/com.atproto.sync.getRepo', $pds),
        ['query' => ['did' => $did]]);
    $stream = $response->getBody();
    return $stream->getContents();
}

/**
 * ディレクトリを削除する
 * @param string $dir 削除するディレクトリのパス
 * @return void
 */
function removeDirectory($dir) {
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $fileinfo) {
            $rmfunc = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $rmfunc($fileinfo->getRealPath());
        }
        
        rmdir($dir);
    }
}

/**
 * Retrieves the posts from a directory.
 *
 * @param string $did The directory path.
 * @return array An array of post objects.
 * @throws \RuntimeException When the directory does not exist.
 */
function outputPosts($did)
{
    if (!is_dir($did))
        throw new  \RuntimeException('Directory does not exist.');
    
    $files = new DirectoryIterator("$did/app.bsky.feed.post");
    $posts = [];
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $content = file_get_contents($file->getRealPath());
            $json = json_decode($content, true);
            $posts[$json['createdAt']] = $json;
        }
    }
    // sort by createdAt
    krsort($posts);
    $posts = array_values($posts);
    $output['posts'] = $posts;
    // parse profile/self.json
    $profile = json_decode(file_get_contents("$did/app.bsky.actor.profile/self.json"));
    $output['profile'] = $profile;
    
    // parse _commit.json
    $_commit = json_decode(file_get_contents("$did/_commit.json"));
    $output['_commit'] = $_commit;
    
    return $output;
}