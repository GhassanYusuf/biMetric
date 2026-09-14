<?php

namespace Zkteco\Protocol\Contracts;

/**
 * A transport that can say how much the OS will hold for it while nobody reads.
 *
 * Separate from Transport because it is a datagram concern. A stream transport has
 * flow control and needs no such question; a fixture transport reading from disk
 * has no answer to give. Only implementations where a pause can LOSE data need to
 * report headroom, so only those implement this.
 *
 * BulkReader uses it to decide how often it may report progress: with enough
 * headroom to hold the whole transfer it can report per chunk, and without it must
 * wait for proven silence. See BulkReader::emit().
 */
interface HasReceiveBuffer
{
    /**
     * Bytes the OS will buffer for this socket, or NULL when it cannot be
     * determined.
     *
     * Null must be read as "assume none" — the safe direction. It is what a build
     * without ext-sockets reports, and guessing generously there is how datagrams
     * get dropped.
     */
    public function receiveBufferBytes(): ?int;
}
