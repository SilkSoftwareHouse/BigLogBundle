<?php

namespace Silksh\BigLogBundle\Command;

use Redis;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Redis2dbCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('silksh:big-log:redis2db')
            ->setDescription('Load data from redis to database.')
            ->addOption('dbdump', null, InputOption::VALUE_NONE, 'Show code to create tables.')
        ;
    }

    protected function dbdump(OutputInterface $output) {
        $output->write("
            CREATE TABLE path (
              id INTEGER AUTO_INCREMENT PRIMARY KEY ,
              value VARCHAR(256) COLLATE 'latin1_bin' UNIQUE
            );

            CREATE TABLE query (
              id INTEGER AUTO_INCREMENT PRIMARY KEY ,
              value VARCHAR(256) COLLATE 'latin1_bin' UNIQUE
            );

            CREATE TABLE redis(
              id INTEGER AUTO_INCREMENT PRIMARY KEY,
              value varchar(256) COLLATE 'latin1_bin' UNIQUE
            );

            CREATE TABLE source (
              id INTEGER AUTO_INCREMENT PRIMARY KEY ,
              value VARCHAR(256) COLLATE 'latin1_bin' UNIQUE
            );

            CREATE TABLE log (
              id INTEGER PRIMARY KEY,
              path INTEGER REFERENCES path(id),
              query INTEGER REFERENCES query(id),
              source INTEGER REFERENCES source(id),
              start TIMESTAMP,
              duration FLOAT
            );
        ");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('dbdump')) {
            return $this->dbdump($output);
        }
        /* @var $redis Redis */
        $container = $this->getContainer();
        $service = $container->get('silksh_big_log.logger');
        $logger = function($x) use($output) {
            $output->write($x, true);
        };
        $service->importFromRedis(4096, $logger);
    }

}
