<?php

namespace Ticketpark\v11;

use Ticketpark\v11\Record\TotalRecord;
use Ticketpark\v11\Record\TransactionCode\TransactionCodeFactory;
use Ticketpark\v11\Record\TransactionRecord;

class v11
{
    protected $lines = array();
    protected $participantIdentifier;
    protected $file;
    protected $error;
    // esr record type3 or type4
    protected $type = 3;

    /**
     * Constructor
     *
     * @param mixed $contents
     */
    public function __construct($contents = null)
    {
        if (is_array($contents)) {
            $this->setLines($contents);
        } elseif (null !== $contents) {
            $this->setFile($contents);
        }
    }

    public function setParticipantIdentifier($participantIdentifier)
    {
        $this->participantIdentifier = $participantIdentifier;
    }

    public function getParticipantIdentifier()
    {
        return $this->participantIdentifier;
    }

    /**
     * Get the transaction records
     *
     * @return array
     */
    public function getTransactionRecords()
    {
        $records = array();

        foreach ($this->getTransactionLines() as $line) {
            $records[] = $this->getTransactionRecord($line);
        }

        return $records;
    }

    /**
     * Get the total record
     *
     * @return TotalRecord
     */
    public function getTotalRecord()
    {
        $record = new TotalRecord();
        $line = $this->getTotalLine();

        if ($transactionCode = TransactionCodeFactory::create(substr($line, 0, 3))) {
            $record->setTransactionCode($transactionCode);
        }

        if ($this->type == 3) {
            $record
                ->setBankingAccount(substr($line, 3, 9))
                ->setSortingKey(substr($line, 12, 27))
                ->setAmount(substr($line, 39, 12) / 100)
                ->setNumberOfTransactions(substr($line, 51, 12) * 1)
                ->setDateFileCreation($this->createDate(substr($line, 63, 6)))
                ->setTotalFees((substr($line, 69, 9)) / 100);
        } else {
            $record
                ->setBankingAccount(substr($line, 6, 9))
                ->setSortingKey(substr($line, 15, 27))
                ->setCurrency(substr($line, 42, 3))
                ->setAmount(substr($line, 45, 9) / 100)
                ->setNumberOfTransactions(substr($line, 57, 12) * 1)
                ->setDateFileCreation($this->createDateLong(substr($line, 69, 8)))
                ->setTotalFees((substr($line, 80, 9)) / 100);
        }

        return $record;
    }

    /**
     * Set path to file containing v11 contents
     *
     * @param string $file
     */
    public function setFile($file)
    {
        $this->file = $file;

        return $this;
    }

    /**
     * Get path to file
     *
     * @return string
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * Set the raw lines
     *
     * @param array $lines
     */
    public function setLines(array $lines)
    {
        $newLines = array();
        foreach($lines as $line) {
            $newLines[] = trim($line);
        }

        $this->lines = $newLines;

        return $this;
    }

    /**
     * Get the raw lines
     *
     * @return array
     */
    public function getLines()
    {
        return $this->lines;
    }

    /**
     * Get the raw transaction lines
     *
     * @return array
     */
    public function getTransactionLines()
    {
        return array_slice($this->getLines(), 0, -1);
    }

    /**
     * Get the raw total line
     *
     * @return string
     */
    public function getTotalLine()
    {
        $lines = $this->getLines();

        return array_pop($lines);
    }

    /**
     * Creates a transaction record
     *
     * @param $line
     * @return Record
     */
    protected function getTransactionRecord($line)
    {
        $record = new TransactionRecord();

        if ($this->type == 3) {

            if ($transactionCode = TransactionCodeFactory::create(substr($line, 0, 3))) {
                $record->setTransactionCode($transactionCode);
            }
            $record->setParticipantIdentifier($this->getParticipantIdentifier())
                ->setBankingAccount(substr($line, 3, 9))
                ->setReferenceNumber(substr($line, 12, 27))
                ->setAmount(substr($line, 39, 10) / 100)
                ->setInternalBankReference(substr($line, 49, 10))
                ->setDatePaid($this->createDate(substr($line, 59, 6)))
                ->setDateProcessed($this->createDate(substr($line, 65, 6)))
                ->setDateCreditNote($this->createDate(substr($line, 71, 6)))
                ->setMicrofilmReference(substr($line, 77, 9))
                ->setRejectCode(substr($line, 86, 1))
                ->setDateValuta($this->createDate(substr($line, 87, 6)))
                ->setFee((substr($line, 96, 4)) / 100);
        } else {
            $record->setTransactionCode(new Record\TransactionCode\TransactionCodeV4(substr($line, 0, 2), substr($line, 2, 1), substr($line, 4, 1)));
            $record->setParticipantIdentifier($this->getParticipantIdentifier())
                ->setBankingAccount(substr($line, 6, 9))
                ->setReferenceNumber(substr($line, 15, 27))
                ->setAmount(substr($line, 45, 12) / 100)
                ->setInternalBankReference(substr($line, 57, 35))
                ->setDatePaid($this->createDateLong(substr($line, 92, 8)))
                ->setDateProcessed($this->createDateLong(substr($line, 100, 8)))
                ->setDateCreditNote($this->createDateLong(substr($line, 108, 8)))
                ->setRejectCode(substr($line, 116, 1))
                ->setFee((substr($line, 120, 4)) / 100);
        }

        return $record;
    }

    /**
     * Validate the v11 contents
     *
     * @return bool
     */
    public function validate()
    {
        if (null !== $this->getFile()) {
            if (is_readable($this->getFile())) {
                $this->setLines(file($this->getFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            } else {
                $this->setError(sprintf('The provided file %s is not readable', $this->getFile()));

                return false;
            }
        }


        if (count($this->getLines()) == 0) {

            return true;
        }

        $lines = $this->getLines();

        // Last line is the total record
        $lastLine = array_pop($lines);

        // validate transactiontype type 3 or 4
        // type3 has record types
        //   999 Gutschrift/Korrektur
        //   995 Storno
        $typesv3 = array(
            999,
            995,
        );
        // type4 has record types CHF 99, EUR 98, Gutschrift/Korrektur 1, Storno 2
        //   991 Gutschrift/Korrektur CHF
        //   992 Storno CHF
        //   981 Gutschrift/Korrektur EUR
        //   982 Storno EUR
        $typesv4 = array(
            991,
            992,
            981,
            982,
        );
        // Check matches any of it
        $transactiontype = substr($lastLine, 0, 3);
        if (!in_array($transactiontype, array_merge($typesv3, $typesv4))) {
            $this->setError(sprintf('Invalid transaction type %s', $transactiontype));

            return false;
        }

        if (in_array($transactiontype, $typesv3)) {
            return $this->validateV3($lastLine, $lines);
        }else{
            $this->type = 4;
            return $this->validateV4($lastLine, $lines);
        }
    }

    public function validateV3($lastLine, $lines)
    {
        // It must have 87 characters and only consist of digits and spaces
        if (strlen($lastLine) != 87) {
            $this->setError(sprintf('The total line contains %s instead of 87 characters', strlen($lastLine)));

            return false;
        }

        if (!preg_match('/^[\d ]+$/', $lastLine)) {
            $this->setError('The total line contains invalid characters. It may only contain digits and spaces');

            return false;
        }

        // Transaction records must have 100 characters and only consist of digits and spaces
        $i=1;
        foreach ($lines as $line) {
            if (strlen($line) != 100) {
                $this->setError(sprintf('Line number %s contains %s instead of 100 characters', $i, strlen($line)));

                return false;
            }

            if (!preg_match('/^[\d a-zA-Z]+$/', $line)) {
                $this->setError(sprintf('Line number %s contains invalid characters. It may only contain digits, letters and spaces', $i));

                return false;
            }

            $i++;
        }

        //Number of transactions must match
        if ($this->getTotalRecord()->getNumberOfTransactions() != count($lines)) {
            $this->setError(sprintf('The number of %s transactions does not match the number of %s transactions according to the total line', count($lines), $this->getTotalRecord()->getNumberOfTransactions()));

            return false;
        }


        // All records must have a transaction code
        if (null == $this->getTotalRecord()->getTransactionCode()) {
            $this->setError(sprintf('The total line does not contain a valid transaction code'));

            return false;
        }

        $i=1;
        foreach($this->getTransactionRecords() as $record){
            if (null == $record->getTransactionCode()) {
                $this->setError(sprintf('Line number %s contains does not contain a valid transaction code', $i));

                return false;
            }
            $i++;
        }

        return true;
    }

    public function validateV4($lastLine, $lines)
    {
        // It must have 91 characters
        if (strlen($lastLine) != 91) {
            $this->setError(sprintf('The total line contains %s instead of 91 characters', strlen($lastLine)));

            return false;
        }

        // Transaction records must have 126 characters
        $i=1;
        foreach ($lines as $line) {
            if (strlen($line) != 126) {
                $this->setError(sprintf('Line number %s contains %s instead of 126 characters', $i, strlen($line)));

                return false;
            }

            $i++;
        }

        //Number of transactions must match
        if ($this->getTotalRecord()->getNumberOfTransactions() != count($lines)) {
            $this->setError(sprintf('The number of %s transactions does not match the number of %s transactions according to the total line', count($lines), $this->getTotalRecord()->getNumberOfTransactions()));

            return false;
        }


        // All records must have a transaction code
        if (null == $this->getTotalRecord()->getTransactionCode()) {
            $this->setError(sprintf('The total line does not contain a valid transaction code'));

            return false;
        }

        $i=1;
        foreach($this->getTransactionRecords() as $record){
            if (null == $record->getTransactionCode()) {
                $this->setError(sprintf('Line number %s does not contain a valid transaction code', $i));

                return false;
            }
            $i++;
        }

        return true;
    }

    /**
     * Get the last error message
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Set error message
     *
     * @param string $error
     */
    protected function setError($error)
    {
        $this->error = $error;
    }

    /**
     * Converts a 6-character string (YYMMDD) to a DateTime
     * @param $string
     * @return \DateTime|null
     */
    protected function createDate($string)
    {
        if ('000000' == $string) {

            return null;
        }

        return new \DateTime(substr($string, 0, 2) . '-' . substr($string, 2, 2) . '-' . substr($string, 4, 2));
    }

    /**
     * Converts a 8-character string (YYYYMMDD) to a DateTime
     * @param $string
     * @return \DateTime|null
     */
    protected function createDateLong($string)
    {
        if ('00000000' == $string) {

            return null;
        }

        return new \DateTime(substr($string, 0, 4) . '-' . substr($string, 4, 2) . '-' . substr($string, 6, 2));
    }
}
